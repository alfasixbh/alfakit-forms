<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

uses(AlfaSix\AlfakitForms\Tests\TestCase::class);

afterEach(function (): void {
    File::deleteDirectory(base_path('alfakit-forms-pages-test'));
});

it('creates the public form page for each renderer', function (string $adapter, string $file, string $import, string $layout): void {
    $this->artisan('alfakit-forms:page', ['--adapter' => $adapter, '--path' => 'alfakit-forms-pages-test'])->assertSuccessful();

    $path = base_path('alfakit-forms-pages-test/alfakit-forms/'.$file);

    expect(File::exists($path))->toBeTrue()
        ->and(File::get($path))->toContain($import)->toContain('AlfakitComponent')
        // A public page: not inside the app's default layout (a starter kit's AppLayout).
        ->and(File::get($path))->toContain($layout);

    // An existing page is kept unless --force.
    File::put($path, 'custom');
    $this->artisan('alfakit-forms:page', ['--adapter' => $adapter, '--path' => 'alfakit-forms-pages-test'])->assertSuccessful();

    expect(File::get($path))->toBe('custom');
})->with([
    'react' => ['react', 'form.tsx', '@alfakit/react', 'AlfakitFormPage.layout = (page: ReactNode) => <>{page}</>'],
    'vue' => ['vue', 'form.vue', '@alfakit/vue', 'defineOptions({ layout: (_h: unknown, page: unknown) => page as VNode })'],
    'svelte' => ['svelte', 'form.svelte', '@alfakit/svelte', 'export const layout = (_h: unknown, page: unknown) => page'],
]);

it('refuses unknown adapters', function (): void {
    $this->artisan('alfakit-forms:page', ['--adapter' => 'angular'])->assertFailed();
});
