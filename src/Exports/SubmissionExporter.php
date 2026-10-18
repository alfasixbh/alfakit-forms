<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Exports;

use AlfaSix\Alfakit\Actions\Exports\ExportColumn;
use AlfaSix\Alfakit\Actions\Exports\Exporter;
use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use AlfaSix\AlfakitForms\AlfakitForms;
use AlfaSix\AlfakitForms\Models\Form;
use AlfaSix\AlfakitForms\Models\FormSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Exports the submissions of one form (CSV and XLSX, on the queue): the submission
 * columns, then one column per field of the form, in form order. The form travels in
 * the export's options (`['form' => $id]`), so the field columns follow its definition
 * at export time.
 */
class SubmissionExporter extends Exporter
{
    /** @var array<string, string>|null field column name => header */
    protected ?array $cachedFieldHeaders = null;

    protected ?Form $cachedForm = null;

    public static function getModel(): string
    {
        return AlfakitForms::submissionModel();
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label(__('alfakit-forms::forms.submissions.fields.id')),
            ExportColumn::make('created_at')->label(__('alfakit-forms::forms.submissions.fields.submitted_at')),
            ExportColumn::make('is_spam')
                ->label(__('alfakit-forms::forms.submissions.fields.is_spam'))
                ->formatStateUsing(static fn (mixed $state): string => (string) __('alfakit-forms::forms.answers.'.($state ? 'yes' : 'no'))),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with('form');
    }

    public function getForm(): ?Form
    {
        if ($this->cachedForm !== null) {
            return $this->cachedForm;
        }

        $key = $this->options['form'] ?? null;

        if (! is_scalar($key)) {
            return null;
        }

        $model = AlfakitForms::formModel();

        /** @var Form|null $form */
        $form = $model::query()->find($key);

        return $this->cachedForm = $form;
    }

    /**
     * @return array<string, string>
     */
    protected function getFieldHeaders(): array
    {
        if ($this->cachedFieldHeaders !== null) {
            return $this->cachedFieldHeaders;
        }

        $headers = [];

        foreach ($this->getForm()?->definition()->getFieldLabels() ?? [] as $name => $label) {
            $headers['answer.'.$name] = $label;
        }

        return $this->cachedFieldHeaders = $headers;
    }

    /**
     * @return array<string, ExportColumn>
     */
    public function getCachedColumns(): array
    {
        if ($this->cachedColumns !== null) {
            return $this->cachedColumns;
        }

        $columns = parent::getCachedColumns();

        foreach ($this->getFieldHeaders() as $column => $label) {
            $name = Str::after($column, 'answer.');

            $columns[$column] = ExportColumn::make($column)
                ->label($label)
                ->state(static fn (Model $record): ?string => $record instanceof FormSubmission ? $record->getFormattedAnswer($name) : null)
                ->exporter($this);
        }

        return $this->cachedColumns = $columns;
    }

    public function getHeaders(): array
    {
        return [...parent::getHeaders(), ...array_values($this->getFieldHeaders())];
    }

    public function getRow(Model $record): array
    {
        $columns = $this->getCachedColumns();
        $row = [];

        foreach ([...array_keys($this->columnMap), ...array_keys($this->getFieldHeaders())] as $name) {
            $row[] = isset($columns[$name]) ? $columns[$name]->getFormattedState($record) : null;
        }

        return $row;
    }

    public function getFileName(Export $export): string
    {
        $form = $this->getForm();

        return 'submissions-'.($form === null ? $export->getKey() : Str::slug($form->slug).'-'.$export->getKey());
    }
}
