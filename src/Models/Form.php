<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Models;

use AlfaSix\AlfakitForms\AlfakitForms;
use AlfaSix\AlfakitForms\Enums\FormStatus;
use AlfaSix\AlfakitForms\Support\FormDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A form built in the panel. `fields` holds the builder's blocks
 * (`[['type' => 'text', 'data' => ['label' => 'Name', 'name' => 'name', …]], …]`) and is
 * normalised whenever it is saved (answer keys generated, unknown blocks dropped);
 * `settings` holds the confirmation, notification and spam options.
 *
 * @property int|string $id
 * @property string|null $tenant_type
 * @property int|string|null $tenant_id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property array<int, array<string, mixed>>|null $fields
 * @property array<string, mixed>|null $settings
 * @property bool $is_published
 * @property Carbon|null $opens_at
 * @property Carbon|null $closes_at
 * @property int|null $max_submissions
 * @property int $views_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $tenant
 */
class Form extends Model
{
    protected $table = 'alfakit_forms';

    protected $guarded = ['id', 'views_count'];

    protected ?FormDefinition $cachedDefinition = null;

    protected ?string $cachedDefinitionHash = null;

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'settings' => 'array',
            'is_published' => 'boolean',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'max_submissions' => 'integer',
            'views_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (Form $form): void {
            $form->setAttribute('fields', FormDefinition::normalize(is_array($form->fields) ? $form->fields : []));

            if (trim((string) $form->getAttribute('slug')) === '') {
                $form->setAttribute('slug', Str::slug($form->title).'-'.Str::lower(Str::random(6)));
            }
        });

        // Submissions go with the form (foreign key cascade); so do their uploaded files,
        // which all live in the form's own directory.
        static::deleted(static function (Form $form): void {
            Storage::disk((string) config('alfakit-forms.uploads.disk', 'local'))
                ->deleteDirectory(trim((string) config('alfakit-forms.uploads.directory', 'alfakit-forms'), '/').'/'.$form->getKey());
        });
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<FormSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(AlfakitForms::submissionModel(), 'form_id');
    }

    public function definition(): FormDefinition
    {
        $fields = is_array($this->fields) ? $this->fields : [];
        $hash = md5(serialize($fields));

        if ($this->cachedDefinition === null || $this->cachedDefinitionHash !== $hash) {
            $this->cachedDefinition = new FormDefinition($fields);
            $this->cachedDefinitionHash = $hash;
        }

        return $this->cachedDefinition;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        $settings = is_array($this->settings) ? $this->settings : [];

        return $settings[$key] ?? $default;
    }

    public function isPublished(): bool
    {
        return (bool) $this->is_published;
    }

    /**
     * Submissions that are not spam: the ones limits, stats and notifications count.
     */
    public function countSubmissions(): int
    {
        return $this->submissions()->where('is_spam', false)->count();
    }

    public function getStatus(?Carbon $now = null): FormStatus
    {
        $now ??= Carbon::now();

        return match (true) {
            ! $this->isPublished() => FormStatus::Draft,
            $this->opens_at !== null && $this->opens_at->isAfter($now) => FormStatus::Scheduled,
            $this->closes_at !== null && ! $this->closes_at->isAfter($now) => FormStatus::Closed,
            $this->max_submissions !== null && $this->max_submissions > 0 && $this->countSubmissions() >= $this->max_submissions => FormStatus::Full,
            default => FormStatus::Open,
        };
    }

    public function acceptsSubmissions(): bool
    {
        return $this->getStatus()->acceptsSubmissions();
    }

    /**
     * The share of views that ended in a (non-spam) submission, 0–100.
     */
    public function getConversionRate(?int $submissions = null): float
    {
        $submissions ??= $this->countSubmissions();

        return $this->views_count > 0 ? round(min(100, $submissions / $this->views_count * 100), 1) : 0.0;
    }

    /**
     * Count a view without touching `updated_at`.
     */
    public function incrementViews(): void
    {
        $this->newQuery()->whereKey($this->getKey())->toBase()->increment('views_count');
        $this->setRawAttributes([...$this->getAttributes(), 'views_count' => $this->views_count + 1], sync: true);
    }

    /**
     * @return array<int, string> the admins notified of new submissions
     */
    public function getNotificationEmails(): array
    {
        $emails = $this->getSetting('notify_emails', []);

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $email): string => is_string($email) ? trim($email) : '', is_array($emails) ? $emails : []),
            static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }
}
