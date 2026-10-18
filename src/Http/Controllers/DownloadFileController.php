<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Http\Controllers;

use AlfaSix\AlfakitForms\AlfakitForms;
use AlfaSix\AlfakitForms\Enums\FieldType;
use AlfaSix\AlfakitForms\Models\FormSubmission;
use AlfaSix\AlfakitForms\Support\Answers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a file answer. The URL is signed and short-lived: the panel only hands it to
 * admins who may view the submission, and the file must belong to that submission's
 * answer, so a signature can't be reused for another path.
 */
class DownloadFileController
{
    public function __invoke(Request $request, string $submission, string $field, int $index): StreamedResponse
    {
        $model = AlfakitForms::submissionModel();

        /** @var FormSubmission|null $record */
        $record = $model::query()->with('form')->find($submission);
        $definition = $record?->form?->definition()->getField($field);

        abort_if($record === null || $definition === null || $definition['type'] !== FieldType::File->value, 404);

        $path = Answers::files($record->getAnswer($field))[$index] ?? null;
        $disk = Storage::disk((string) config('alfakit-forms.uploads.disk', 'local'));

        abort_if($path === null || str_contains($path, '..') || ! $disk->exists($path), 404);

        return $disk->download($path, basename($path));
    }
}
