<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Models;

use AlfaSix\AlfakitForms\AlfakitForms;
use AlfaSix\AlfakitForms\Enums\FieldType;
use AlfaSix\AlfakitForms\Support\Answers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One response to a form. `data` holds the answers keyed by the fields' names, as
 * validated against the form definition at the time of the submission.
 *
 * @property int|string $id
 * @property int|string $form_id
 * @property string|null $tenant_type
 * @property int|string|null $tenant_id
 * @property string|null $submitter_type
 * @property int|string|null $submitter_id
 * @property array<string, mixed>|null $data
 * @property bool $is_spam
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Form|null $form
 * @property-read Model|null $submitter
 */
class FormSubmission extends Model
{
    protected $table = 'alfakit_form_submissions';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Uploaded answers go with the submission.
        static::deleted(static function (FormSubmission $submission): void {
            $form = $submission->form;

            if ($form === null) {
                return;
            }

            $disk = Storage::disk((string) config('alfakit-forms.uploads.disk', 'local'));

            foreach ($form->definition()->getFields() as $name => $field) {
                if ($field['type'] === FieldType::File->value) {
                    foreach (Answers::files($submission->getAnswer($name)) as $path) {
                        $disk->delete($path);
                    }
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_spam' => 'boolean',
        ];
    }

    /**
     * Answers keep their characters as typed (no `\u` escapes), so the submissions
     * table can search them in any language.
     *
     * @param  mixed  $value
     * @param  int  $flags
     */
    protected function asJson($value, $flags = 0): string
    {
        return (string) parent::asJson($value, $flags | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return BelongsTo<Form, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(AlfakitForms::formModel(), 'form_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function submitter(): MorphTo
    {
        return $this->morphTo();
    }

    public function getAnswer(string $name): mixed
    {
        $data = is_array($this->data) ? $this->data : [];

        return $data[$name] ?? null;
    }

    /**
     * The answer as text, the way admins read it (lists joined, booleans as yes/no).
     */
    public function getFormattedAnswer(string $name): ?string
    {
        $field = $this->form?->definition()->getField($name);

        return Answers::format($field, $this->getAnswer($name));
    }
}
