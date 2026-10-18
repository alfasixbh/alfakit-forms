<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Resources\FormResource\RelationManagers;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\DeleteBulkAction;
use AlfaSix\Alfakit\Actions\ExportAction;
use AlfaSix\Alfakit\Actions\ViewAction;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\TernaryFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitForms\AlfakitForms;
use AlfaSix\AlfakitForms\Enums\FieldType;
use AlfaSix\AlfakitForms\Exports\SubmissionExporter;
use AlfaSix\AlfakitForms\FormsPlugin;
use AlfaSix\AlfakitForms\Models\Form;
use AlfaSix\AlfakitForms\Models\FormSubmission;
use AlfaSix\AlfakitForms\Support\Answers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * The submissions of a form: the first answers as columns, a view modal with every
 * answer (file answers as signed download links), spam flags, delete and export.
 */
class SubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'submissions';

    protected static ?string $icon = 'inbox';

    /** How many answers show as table columns (the view modal shows all of them). */
    protected static int $answerColumns = 4;

    public static function getTitle(): string
    {
        return static::$title ?? (string) __('alfakit-forms::forms.submissions.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $plugin = FormsPlugin::get();

        return $plugin->hasAuthorization() ? $plugin->isAuthorized() : FormsPlugin::get()->getResourceClass()::canView($ownerRecord);
    }

    /**
     * Submissions are managed (spam, delete) on the form's view page by whoever may edit
     * the form, unlike the read-only default of relation managers on view pages.
     */
    public function isReadOnly(): bool
    {
        return ! FormsPlugin::get()->getResourceClass()::canEdit($this->getOwnerRecord());
    }

    public function getModelLabel(): string
    {
        return (string) __('alfakit-forms::forms.submissions.label');
    }

    public function getPluralModelLabel(): string
    {
        return (string) __('alfakit-forms::forms.submissions.plural_label');
    }

    protected function getForm(): Form
    {
        $owner = $this->getOwnerRecord();

        abort_unless($owner instanceof Form, 404);

        return $owner;
    }

    public function table(Table $table): Table
    {
        $form = $this->getForm();
        $labels = $form->definition()->getFieldLabels();

        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('alfakit-forms::forms.submissions.fields.submitted_at'))
                    ->dateTime()
                    ->sortable(),
                ...array_map(
                    static fn (string $name, string $label): TextColumn => TextColumn::make('answer_'.$name)
                        ->label($label)
                        ->state(static fn (FormSubmission $record): ?string => $record->getFormattedAnswer($name))
                        ->limit(60)
                        ->placeholder('—'),
                    array_slice(array_keys($labels), 0, static::$answerColumns),
                    array_slice(array_values($labels), 0, static::$answerColumns),
                ),
                TextColumn::make('is_spam')
                    ->label(__('alfakit-forms::forms.submissions.fields.is_spam'))
                    ->state(static fn (FormSubmission $record): ?string => $record->is_spam ? (string) __('alfakit-forms::forms.submissions.spam') : null)
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),
            ])
            ->searchable()
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('form'))
            ->searchUsing(static fn (Builder $query, string $search): Builder => $query->where('data', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->filters([
                TernaryFilter::make('is_spam')
                    ->label(__('alfakit-forms::forms.submissions.fields.is_spam'))
                    ->default(['value' => '0']),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(SubmissionExporter::class)
                    ->columnMapping(false)
                    ->options(['form' => $form->getKey()]),
            ])
            ->recordActions([
                ViewAction::make()
                    ->modalHeading(__('alfakit-forms::forms.submissions.view_heading'))
                    ->modalContent(static fn (FormSubmission $record): array => static::getAnswerEntries($form, $record)),
                Action::make('markAsSpam')
                    ->label(__('alfakit-forms::forms.submissions.actions.mark_spam'))
                    ->icon('shield-alert')
                    ->color('warning')
                    ->visible(static fn (FormSubmission $record): bool => ! $record->is_spam)
                    ->authorize(fn (): bool => ! $this->isReadOnly())
                    ->action(static function (FormSubmission $record, Action $action): void {
                        $record->forceFill(['is_spam' => true])->save();
                        $action->sendSuccessNotification();
                    })
                    ->successNotificationTitle(__('alfakit-forms::forms.submissions.notifications.marked_spam')),
                Action::make('markAsNotSpam')
                    ->label(__('alfakit-forms::forms.submissions.actions.mark_not_spam'))
                    ->icon('shield-check')
                    ->color('gray')
                    ->visible(static fn (FormSubmission $record): bool => $record->is_spam)
                    ->authorize(fn (): bool => ! $this->isReadOnly())
                    ->action(static function (FormSubmission $record, Action $action): void {
                        $record->forceFill(['is_spam' => false])->save();
                        $action->sendSuccessNotification();
                    })
                    ->successNotificationTitle(__('alfakit-forms::forms.submissions.notifications.marked_not_spam')),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('markAsSpam')
                    ->label(__('alfakit-forms::forms.submissions.actions.mark_spam'))
                    ->icon('shield-alert')
                    ->color('warning')
                    ->action(static function (Collection $records, BulkAction $action): void {
                        foreach ($records as $record) {
                            $record->forceFill(['is_spam' => true])->save();
                        }

                        $action->sendSuccessNotification();
                    })
                    ->authorize(fn (): bool => ! $this->isReadOnly())
                    ->deselectRecordsAfterCompletion()
                    ->successNotificationTitle(__('alfakit-forms::forms.submissions.notifications.marked_spam')),
                DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->stackedOnMobile()
            ->emptyStateIcon('inbox')
            ->emptyStateHeading(__('alfakit-forms::forms.submissions.empty.heading'))
            ->emptyStateDescription(__('alfakit-forms::forms.submissions.empty.description'));
    }

    /**
     * Every answer of a submission, in form order. File answers link to a signed URL that
     * is valid for 30 minutes.
     *
     * @return array<Component>
     */
    public static function getAnswerEntries(Form $form, FormSubmission $record): array
    {
        $entries = [];

        foreach ($form->definition()->getFields() as $name => $field) {
            $label = $form->definition()->getFieldLabels()[$name] ?? $name;

            if ($field['type'] === FieldType::File->value) {
                $files = Answers::files($record->getAnswer($name));

                if ($files === []) {
                    $entries[] = TextEntry::make('answer_'.$name)->label($label)->state(null)->placeholder('—');
                }

                foreach ($files as $index => $path) {
                    $entries[] = TextEntry::make('answer_'.$name.'_'.$index)
                        ->label($index === 0 ? $label : null)
                        ->hiddenLabel($index > 0)
                        ->state(basename($path))
                        ->icon('paperclip')
                        ->url(static::getFileUrl($record, $name, $index), shouldOpenInNewTab: true);
                }

                continue;
            }

            $entries[] = TextEntry::make('answer_'.$name)
                ->label($label)
                ->state($record->getFormattedAnswer($name))
                ->placeholder('—');
        }

        return [
            Section::make(__('alfakit-forms::forms.submissions.answers'))
                ->columns(['default' => 1, 'md' => 2])
                ->schema($entries),
            Section::make(__('alfakit-forms::forms.submissions.details'))
                ->columns(['default' => 1, 'md' => 2])
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextEntry::make('created_at')->label(__('alfakit-forms::forms.submissions.fields.submitted_at'))->state($record->created_at)->dateTime(),
                    TextEntry::make('ip_address')->label(__('alfakit-forms::forms.submissions.fields.ip_address'))->state($record->ip_address)->placeholder('—'),
                    TextEntry::make('user_agent')->label(__('alfakit-forms::forms.submissions.fields.user_agent'))->state($record->user_agent)->placeholder('—')->columnSpanFull(),
                ]),
        ];
    }

    public static function getFileUrl(FormSubmission $submission, string $field, int $index): ?string
    {
        $name = AlfakitForms::routeName('files');

        if (! Route::has($name)) {
            return null;
        }

        return URL::temporarySignedRoute($name, now()->addMinutes(30), [
            'submission' => $submission->getKey(),
            'field' => $field,
            'index' => $index,
        ]);
    }
}
