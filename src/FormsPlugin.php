<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms;

use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Concerns\HasPoweredByCredit;
use AlfaSix\AlfakitForms\Resources\FormResource;
use Closure;
use InvalidArgumentException;

/**
 * The form builder in a panel: a Forms resource with the visual builder, the submissions
 * of each form, exports and stats. The public side (the `/forms/{slug}` route and
 * `AlfakitForms::embed()`) works outside panels and is configured in
 * `config/alfakit-forms.php`.
 *
 * ```php
 * $panel->plugin(
 *     FormsPlugin::make()
 *         ->navigationGroup('Content')
 *         ->authorize(fn (User $user): bool => $user->is_admin),
 * );
 * ```
 */
class FormsPlugin implements Plugin
{
    use EvaluatesClosures;
    use HasPoweredByCredit;

    public const ID = 'forms';

    /** @var class-string<FormResource> */
    protected string $resource = FormResource::class;

    protected string|Closure|null $label = null;

    protected string|Closure|null $pluralLabel = null;

    protected string|Closure|null $navigationLabel = null;

    protected string|Closure|null $navigationGroup = null;

    protected string|Closure|null $navigationIcon = 'clipboard-list';

    protected int|Closure|null $navigationSort = null;

    protected bool|Closure $shouldRegisterNavigation = true;

    protected bool|Closure|null $authorizeUsing = null;

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $fieldTypes = null;

    public static function make(): static
    {
        return new static;
    }

    /**
     * The plugin registered on the current panel, or a plugin with the default options when
     * the panel doesn't have it (a resource registered by hand).
     */
    public static function get(): static
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        if ($panel->hasPlugin(self::ID)) {
            $plugin = $panel->getPlugin(self::ID);

            if ($plugin instanceof static) {
                return $plugin;
            }
        }

        return static::make();
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([$this->getResourceClass()]);
    }

    public function boot(Panel $panel): void {}

    protected function getEvaluationIdentifier(): string
    {
        return 'plugin';
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'panel' => [alfakit()->getCurrentOrDefaultPanel()],
            'user' => [alfakit()->getCurrentOrDefaultPanel()->auth()->user()],
            'tenant' => [alfakit()->getTenant()],
            default => [],
        };
    }

    // ---------------------------------------------------------------- Resource

    /**
     * Replace the resource with your own subclass of `FormResource`.
     *
     * @param  class-string<FormResource>  $resource
     */
    public function resourceClass(string $resource): static
    {
        if (! is_a($resource, FormResource::class, true)) {
            throw new InvalidArgumentException("The forms resource [{$resource}] must extend [".FormResource::class.'].');
        }

        $this->resource = $resource;

        return $this;
    }

    /**
     * @return class-string<FormResource>
     */
    public function getResourceClass(): string
    {
        return $this->resource;
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        return (string) ($this->evaluate($this->label) ?? __('alfakit-forms::forms.resource.label'));
    }

    public function pluralLabel(string|Closure|null $label): static
    {
        $this->pluralLabel = $label;

        return $this;
    }

    public function getPluralLabel(): string
    {
        return (string) ($this->evaluate($this->pluralLabel) ?? __('alfakit-forms::forms.resource.plural_label'));
    }

    /**
     * Limit the blocks admins can add (`['text', 'email', 'textarea', 'page_break']`).
     * Every type is offered by default.
     *
     * @param  array<int, string>|Closure|null  $types
     */
    public function fieldTypes(array|Closure|null $types): static
    {
        $this->fieldTypes = $types;

        return $this;
    }

    /**
     * @return array<int, Enums\FieldType>
     */
    public function getFieldTypes(): array
    {
        $types = $this->evaluate($this->fieldTypes);

        if (! is_array($types)) {
            return Enums\FieldType::cases();
        }

        return array_values(array_filter(array_map(
            static fn (mixed $type): ?Enums\FieldType => $type instanceof Enums\FieldType ? $type : (is_string($type) ? Enums\FieldType::tryFrom($type) : null),
            $types,
        )));
    }

    // ---------------------------------------------------------------- Navigation

    public function navigationLabel(string|Closure|null $label): static
    {
        $this->navigationLabel = $label;

        return $this;
    }

    public function getNavigationLabel(): string
    {
        return (string) ($this->evaluate($this->navigationLabel) ?? __('alfakit-forms::forms.resource.navigation_label'));
    }

    public function navigationGroup(string|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        $group = $this->evaluate($this->navigationGroup);

        return is_string($group) ? $group : null;
    }

    public function navigationIcon(string|Closure|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function getNavigationIcon(): ?string
    {
        $icon = $this->evaluate($this->navigationIcon);

        return is_string($icon) ? $icon : null;
    }

    public function navigationSort(int|Closure|null $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        $sort = $this->evaluate($this->navigationSort);

        return is_int($sort) ? $sort : null;
    }

    public function registerNavigation(bool|Closure $condition = true): static
    {
        $this->shouldRegisterNavigation = $condition;

        return $this;
    }

    public function shouldRegisterNavigation(): bool
    {
        return (bool) $this->evaluate($this->shouldRegisterNavigation);
    }

    // ---------------------------------------------------------------- Authorisation

    /**
     * Who may manage forms and read submissions. Without it, the policies of the form and
     * submission models decide (`viewAny`, `create`, `update`, `delete`…), and everyone
     * signed in to the panel may when there are none.
     */
    public function authorize(bool|Closure|null $callback = true): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    public function hasAuthorization(): bool
    {
        return $this->authorizeUsing !== null;
    }

    public function isAuthorized(): bool
    {
        return (bool) $this->evaluate($this->authorizeUsing);
    }
}
