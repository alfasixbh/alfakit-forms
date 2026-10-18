<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Resources;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\DeleteBulkAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Actions\ReplicateAction;
use AlfaSix\Alfakit\Actions\ViewAction;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Forms\Components\TagsInput;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Forms\Components\ToggleButtons;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Tabs;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\TernaryFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitForms\AlfakitForms;
use AlfaSix\AlfakitForms\FormsPlugin;
use AlfaSix\AlfakitForms\Models\Form;
use AlfaSix\AlfakitForms\Resources\FormResource\Pages;
use AlfaSix\AlfakitForms\Resources\FormResource\RelationManagers\SubmissionsRelationManager;
use AlfaSix\AlfakitForms\Support\BuilderBlocks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Forms built in the panel. The edit page holds the visual builder and the settings;
 * the view page shows the stats and the submissions. Extend it and pass your class to
 * `FormsPlugin::make()->resourceClass(...)` to change any of it.
 */
class FormResource extends Resource
{
    protected static ?string $slug = 'forms';

    protected static ?string $recordTitleAttribute = 'title';

    protected static bool $isDiscovered = false;

    /** Forms belong to the panel's tenant through the `tenant` morph relationship. */
    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    public static function getModel(): string
    {
        return AlfakitForms::formModel();
    }

    public static function getPlugin(): FormsPlugin
    {
        return FormsPlugin::get();
    }

    public static function getModelLabel(): string
    {
        return static::getPlugin()->getLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::getPlugin()->getPluralLabel();
    }

    public static function getNavigationLabel(): string
    {
        return static::getPlugin()->getNavigationLabel();
    }

    public static function getNavigationGroup(): ?string
    {
        return static::getPlugin()->getNavigationGroup();
    }

    public static function getNavigationIcon(): ?string
    {
        return static::getPlugin()->getNavigationIcon();
    }

    public static function getNavigationSort(): ?int
    {
        return static::getPlugin()->getNavigationSort();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::getPlugin()->shouldRegisterNavigation();
    }

    /**
     * Polymorphic tenancy: a form belongs to a tenant of any model (type and key).
     */
    public static function scopeEloquentQueryToTenant(Builder $query, Model $tenant): Builder
    {
        $relationship = $query->getModel()->{static::getTenantOwnershipRelationshipName()}();

        if ($relationship instanceof MorphTo) {
            return $query
                ->where($query->qualifyColumn($relationship->getMorphType()), $tenant->getMorphClass())
                ->where($query->qualifyColumn($relationship->getForeignKeyName()), $tenant->getKey());
        }

        return parent::scopeEloquentQueryToTenant($query, $tenant);
    }

    // ---------------------------------------------------------------- Authorisation

    public static function can(string $action, ?Model $record = null): bool
    {
        $plugin = static::getPlugin();

        return $plugin->hasAuthorization() ? $plugin->isAuthorized() : parent::can($action, $record);
    }

    // ---------------------------------------------------------------- Definition

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('form')
                ->key('form-tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make(__('alfakit-forms::forms.resource.tabs.build'))
                        ->key('build')
                        ->icon('blocks')
                        ->schema([
                            TextInput::make('title')
                                ->label(__('alfakit-forms::forms.resource.fields.title'))
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(static function (Get $get, Set $set, ?string $state, string $operation): void {
                                    if ($operation === 'create' && blank($get('slug'))) {
                                        $set('slug', Str::slug((string) $state));
                                    }
                                }),
                            Textarea::make('description')
                                ->label(__('alfakit-forms::forms.resource.fields.description'))
                                ->helperText(__('alfakit-forms::forms.resource.fields.description_help'))
                                ->rows(2)
                                ->maxLength(2000),
                            BuilderBlocks::make('fields', static::getPlugin()->getFieldTypes())
                                ->label(__('alfakit-forms::forms.resource.fields.fields'))
                                ->columnSpanFull(),
                        ]),
                    Tab::make(__('alfakit-forms::forms.resource.tabs.settings'))
                        ->key('settings')
                        ->icon('settings')
                        ->schema(static::settingsSchema()),
                ]),
        ]);
    }

    /**
     * @return array<\AlfaSix\Alfakit\Schemas\Components\Component>
     */
    protected static function settingsSchema(): array
    {
        return [
            Section::make(__('alfakit-forms::forms.resource.sections.publishing'))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextInput::make('slug')
                        ->label(__('alfakit-forms::forms.resource.fields.slug'))
                        ->helperText(__('alfakit-forms::forms.resource.fields.slug_help'))
                        ->required()
                        ->alphaDash()
                        ->maxLength(120)
                        ->unique(ignoreRecord: true),
                    Toggle::make('is_published')
                        ->label(__('alfakit-forms::forms.resource.fields.is_published'))
                        ->helperText(__('alfakit-forms::forms.resource.fields.is_published_help')),
                    DateTimePicker::make('opens_at')
                        ->label(__('alfakit-forms::forms.resource.fields.opens_at'))
                        ->seconds(false),
                    DateTimePicker::make('closes_at')
                        ->label(__('alfakit-forms::forms.resource.fields.closes_at'))
                        ->seconds(false)
                        ->after('opens_at', static fn (Get $get): bool => filled($get('opens_at'))),
                    TextInput::make('max_submissions')
                        ->label(__('alfakit-forms::forms.resource.fields.max_submissions'))
                        ->helperText(__('alfakit-forms::forms.resource.fields.max_submissions_help'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(1000000),
                    Textarea::make('settings.closed_message')
                        ->label(__('alfakit-forms::forms.resource.fields.closed_message'))
                        ->rows(2)
                        ->maxLength(1000),
                ]),
            Section::make(__('alfakit-forms::forms.resource.sections.after_submit'))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextInput::make('settings.submit_label')
                        ->label(__('alfakit-forms::forms.resource.fields.submit_label'))
                        ->placeholder(__('alfakit-forms::forms.public.submit'))
                        ->maxLength(60),
                    ToggleButtons::make('settings.confirmation_type')
                        ->label(__('alfakit-forms::forms.resource.fields.confirmation_type'))
                        ->options([
                            'message' => __('alfakit-forms::forms.resource.confirmation.message'),
                            'redirect' => __('alfakit-forms::forms.resource.confirmation.redirect'),
                        ])
                        ->icons(['message' => 'message-square-text', 'redirect' => 'external-link'])
                        ->default('message')
                        ->inline()
                        ->grouped()
                        ->live(),
                    Textarea::make('settings.confirmation_message')
                        ->label(__('alfakit-forms::forms.resource.fields.confirmation_message'))
                        ->placeholder(__('alfakit-forms::forms.public.submitted.description'))
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull()
                        ->visible(static fn (Get $get): bool => $get('settings.confirmation_type') !== 'redirect'),
                    TextInput::make('settings.redirect_url')
                        ->label(__('alfakit-forms::forms.resource.fields.redirect_url'))
                        ->helperText(__('alfakit-forms::forms.resource.fields.redirect_url_help'))
                        ->url()
                        ->rule('starts_with:http://,https://')
                        ->maxLength(2048)
                        ->required(static fn (Get $get): bool => $get('settings.confirmation_type') === 'redirect')
                        ->columnSpanFull()
                        ->visible(static fn (Get $get): bool => $get('settings.confirmation_type') === 'redirect'),
                    TextInput::make('settings.first_page_label')
                        ->label(__('alfakit-forms::forms.resource.fields.first_page_label'))
                        ->helperText(__('alfakit-forms::forms.resource.fields.first_page_label_help'))
                        ->maxLength(120),
                ]),
            Section::make(__('alfakit-forms::forms.resource.sections.notifications'))
                ->description(__('alfakit-forms::forms.resource.sections.notifications_description'))
                ->schema([
                    TagsInput::make('settings.notify_emails')
                        ->label(__('alfakit-forms::forms.resource.fields.notify_emails'))
                        ->placeholder(__('alfakit-forms::forms.resource.fields.notify_emails_placeholder'))
                        ->nestedRecursiveRules(['email', 'max:255'])
                        ->splitKeys(['Tab', ',', ' '])
                        ->rule('array')
                        ->rule('max:20'),
                ]),
            Section::make(__('alfakit-forms::forms.resource.sections.spam'))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Toggle::make('settings.honeypot')
                        ->label(__('alfakit-forms::forms.resource.fields.honeypot'))
                        ->helperText(__('alfakit-forms::forms.resource.fields.honeypot_help'))
                        ->default(true),
                    Toggle::make('settings.captcha')
                        ->label(__('alfakit-forms::forms.resource.fields.captcha'))
                        ->helperText(fn (): string => AlfakitForms::manager()->hasCaptcha()
                            ? (string) __('alfakit-forms::forms.resource.fields.captcha_help')
                            : (string) __('alfakit-forms::forms.resource.fields.captcha_unavailable'))
                        ->disabled(fn (): bool => ! AlfakitForms::manager()->hasCaptcha()),
                ]),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('alfakit-forms::forms.resource.sections.overview'))
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('status')
                        ->label(__('alfakit-forms::forms.resource.fields.status'))
                        ->state(static fn (Form $record): string => $record->getStatus()->getLabel())
                        ->badge()
                        ->color(static fn (Form $record): string => $record->getStatus()->getColor()),
                    TextEntry::make('public_url')
                        ->label(__('alfakit-forms::forms.resource.fields.public_url'))
                        ->state(static fn (Form $record): ?string => AlfakitForms::url($record))
                        ->url(static fn (Form $record): ?string => $record->isPublished() ? AlfakitForms::url($record) : null, shouldOpenInNewTab: true)
                        ->copyable()
                        ->placeholder(__('alfakit-forms::forms.resource.fields.no_public_url')),
                    TextEntry::make('updated_at')
                        ->label(__('alfakit-forms::forms.resource.fields.updated_at'))
                        ->since(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->withCount([
                'submissions as submissions_count' => static fn (Builder $query): Builder => $query->where('is_spam', false),
            ]))
            ->columns([
                TextColumn::make('title')
                    ->label(__('alfakit-forms::forms.resource.fields.title'))
                    ->description(static fn (Form $record): string => '/'.trim((string) config('alfakit-forms.route.prefix', 'forms'), '/').'/'.$record->slug)
                    ->searchable(['title', 'slug'])
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('status')
                    ->label(__('alfakit-forms::forms.resource.fields.status'))
                    ->state(static fn (Form $record): string => $record->getStatus()->getLabel())
                    ->badge()
                    ->color(static fn (Form $record): string => $record->getStatus()->getColor()),
                TextColumn::make('submissions_count')
                    ->label(__('alfakit-forms::forms.resource.fields.submissions'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('views_count')
                    ->label(__('alfakit-forms::forms.resource.fields.views'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('conversion')
                    ->label(__('alfakit-forms::forms.resource.fields.conversion'))
                    ->state(static fn (Form $record): float => $record->getConversionRate((int) $record->getAttribute('submissions_count')))
                    ->suffix('%')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label(__('alfakit-forms::forms.resource.fields.updated_at'))
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_published')
                    ->label(__('alfakit-forms::forms.resource.fields.is_published')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                static::getOpenAction(),
                ReplicateAction::make()
                    ->excludeAttributes(['views_count', 'submissions_count'])
                    ->beforeReplicaSaved(static function (Form $replica): void {
                        $replica->setAttribute('title', __('alfakit-forms::forms.resource.copy_of', ['title' => $replica->title]));
                        $replica->setAttribute('slug', '');
                        $replica->setAttribute('is_published', false);
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->stackedOnMobile()
            ->emptyStateIcon('clipboard-list')
            ->emptyStateHeading(__('alfakit-forms::forms.resource.empty.heading'))
            ->emptyStateDescription(__('alfakit-forms::forms.resource.empty.description'));
    }

    /**
     * Opens the public form in a new tab (published forms only).
     */
    public static function getOpenAction(): Action
    {
        return Action::make('open')
            ->label(__('alfakit-forms::forms.resource.actions.open'))
            ->icon('external-link')
            ->color('gray')
            ->url(static fn (Form $record): ?string => AlfakitForms::url($record), shouldOpenInNewTab: true)
            ->visible(static fn (Form $record): bool => $record->isPublished() && AlfakitForms::url($record) !== null);
    }

    /**
     * Opens a signed, read-only preview of the form (drafts too) for an hour.
     */
    public static function getPreviewAction(): Action
    {
        return Action::make('preview')
            ->label(__('alfakit-forms::forms.resource.actions.preview'))
            ->icon('eye')
            ->color('gray')
            ->url(static fn (Form $record): ?string => static::getPreviewUrl($record), shouldOpenInNewTab: true)
            ->visible(static fn (Form $record): bool => static::getPreviewUrl($record) !== null);
    }

    public static function getPreviewUrl(Form $form): ?string
    {
        $name = AlfakitForms::routeName('show');

        if (! \Illuminate\Support\Facades\Route::has($name)) {
            return null;
        }

        return URL::temporarySignedRoute($name, now()->addHour(), ['slug' => $form->slug, 'preview' => 1]);
    }

    public static function getRelations(): array
    {
        return [SubmissionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListForms::route('/'),
            'create' => Pages\CreateForm::route('/create'),
            'view' => Pages\ViewForm::route('/{record}'),
            'edit' => Pages\EditForm::route('/{record}/edit'),
        ];
    }
}
