<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Http\Controllers;

use AlfaSix\AlfakitForms\AlfakitForms;
use AlfaSix\AlfakitForms\FormsPlugin;
use AlfaSix\AlfakitForms\Models\Form;
use AlfaSix\AlfakitForms\Standalone\PublicForm;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /{prefix}/{slug}: a published form on its own page, outside every panel. A signed
 * URL from the panel's "Preview" action also shows drafts (read only).
 */
class ShowFormController
{
    public function __invoke(Request $request, string $slug): Response
    {
        $model = AlfakitForms::formModel();
        $preview = $request->boolean('preview') && $request->hasValidSignature();

        /** @var Form|null $form */
        $form = $model::query()->where('slug', $slug)->first();

        abort_if($form === null || (! $form->isPublished() && ! $preview), 404);

        $component = PublicForm::for($form, $preview);
        $component->recordView();

        return Inertia::render((string) config('alfakit-forms.page', 'alfakit-forms/form'), [
            'form' => $component,
            'meta' => [
                'title' => $form->title,
                'description' => $form->description,
                'slug' => $form->slug,
                'preview' => $preview,
                // "Powered by AlfaKit · Alfa Six" (`FormsPlugin::poweredBy()`), off by default.
                'poweredBy' => FormsPlugin::get()->getPoweredByHtml(),
            ],
        ]);
    }
}
