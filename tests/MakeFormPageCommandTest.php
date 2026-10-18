<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

uses(AlfaSix\AlfakitForms\Tests\TestCase::class);

afterEach(function (): void {
    File::deleteDirectory(base_path('alfakit-forms-pages-test'));
});

it('creates the public form page for each renderer', function (string $adapter, string $file, string $import): void {
    $this->artisan('alfakit-forms:page', ['--adapter' => $adapter, '--path' => 'alfakit-forms-pages-test'])->assertSuccessful();

    $path = base_path('alfakit-forms-pages-test/alfakit-forms/'.$file);

    expect(File::exists($path))->toBeTrue()
        ->and(File::get($path))->toContain($import)->toContain('AlfakitComponent');

    // An existing page is kept unless --force.
    File::put($path, 'custom');
    $this->artisan('alfakit-forms:page', ['--adapter' => $adapter, '--path' => 'alfakit-forms-pages-test'])->assertSuccessful();

    expect(File::get($path))->toBe('custom');
})->with([
    'react' => ['react', 'form.tsx', '@alfakit/react'],
    'vue' => ['vue', 'form.vue', '@alfakit/vue'],
    'svelte' => ['svelte', 'form.svelte', '@alfakit/svelte'],
]);

it('refuses unknown adapters', function (): void {
    $this->artisan('alfakit-forms:page', ['--adapter' => 'angular'])->assertFailed();
});
