<?php

declare(strict_types=1);

use AlfaSix\AlfakitForms\Http\Controllers\DownloadFileController;
use AlfaSix\AlfakitForms\Http\Controllers\ShowFormController;
use Illuminate\Support\Facades\Route;

$route = Route::middleware((array) config('alfakit-forms.route.middleware', ['web']))
    ->prefix(trim((string) config('alfakit-forms.route.prefix', 'forms'), '/'))
    ->name((string) config('alfakit-forms.route.name', 'alfakit-forms.'));

if (is_string($domain = config('alfakit-forms.route.domain')) && $domain !== '') {
    $route->domain($domain);
}

$route->group(function (): void {
    Route::get('_files/{submission}/{field}/{index}', DownloadFileController::class)
        ->middleware('signed')
        ->whereNumber('index')
        ->name('files');

    Route::get('{slug}', ShowFormController::class)
        ->where('slug', '[A-Za-z0-9_-]+')
        ->name('show');
});
