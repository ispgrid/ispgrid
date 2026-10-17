<?php

use App\Support\Addons\PrivateFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('addons');
    Route::get('/test-private-file/{path}/{inline?}', fn (string $path, ?string $inline = null) => PrivateFile::response(
        'documents/'.$path,
        'Customer file '.$path,
        inline: $inline === 'inline',
    ))->where('path', '[a-z0-9.-]+');
    Route::get('/test-private-content/{name}/{inline?}', fn (string $name, ?string $inline = null) => PrivateFile::contentResponse(
        match ($name) {
            'png' => pngBytes(),
            'pdf' => pdfBytes(),
            default => '<html><script>alert(1)</script></html>',
        },
        'Customer file '.$name,
        inline: $inline === 'inline',
    ));
});

/**
 * The smallest valid PNG and PDF, so the stored file's type is detected from its bytes.
 */
function pngBytes(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
}

function pdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
}

it('keeps add-on files on a private disk', function () {
    expect(config('filesystems.disks.addons.root'))->toBe(storage_path('app/private/addons'))
        ->and(config('filesystems.disks.addons.serve'))->toBeFalse();
});

it('downloads a file as an attachment by default', function () {
    Storage::disk('addons')->put('documents/a.png', pngBytes());

    $response = $this->get('/test-private-file/a.png');

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->toContain('Customer file a.png');
});

it('shows an image inline in a sandbox', function () {
    Storage::disk('addons')->put('documents/a.png', pngBytes());

    $response = $this->get('/test-private-file/a.png/inline');

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Security-Policy', 'sandbox');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline;');
});

it('shows a PDF inline without the sandbox, which would stop the browser showing it', function () {
    Storage::disk('addons')->put('documents/a.pdf', pdfBytes());

    $response = $this->get('/test-private-file/a.pdf/inline');

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeaderMissing('Content-Security-Policy');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline;');
});

it('never shows HTML or SVG inline, whatever the file is called', function (string $name, string $bytes) {
    Storage::disk('addons')->put("documents/{$name}", $bytes);

    $response = $this->get("/test-private-file/{$name}/inline");

    $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream');
})->with([
    'html' => ['page.html', '<html><body><script>alert(1)</script></body></html>'],
    'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'html named as png' => ['fake.png', '<html><script>alert(1)</script></html>'],
]);

it('answers 404 for a missing file', function () {
    $this->get('/test-private-file/missing.png')->assertNotFound();
});

it('sends given bytes as an attachment by default', function () {
    $response = $this->get('/test-private-content/png');

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->toContain('Customer file png');
});

it('shows given picture bytes inline in a sandbox', function () {
    $response = $this->get('/test-private-content/png/inline');

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Security-Policy', 'sandbox');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline;');
});

it('shows given PDF bytes inline without the sandbox', function () {
    $response = $this->get('/test-private-content/pdf/inline');

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeaderMissing('Content-Security-Policy');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline;');
});

it('downloads given HTML bytes even when asked to show them', function () {
    $response = $this->get('/test-private-content/html/inline');

    $response->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;');
});

it('accepts a download name with a slash', function () {
    Route::get('/test-private-slash', fn () => PrivateFile::contentResponse(pngBytes(), 'ID/Address proof.png'));

    $response = $this->get('/test-private-slash');

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('ID-Address proof.png');
});

it('sends the given bytes unchanged', function () {
    expect($this->get('/test-private-content/png')->getContent())->toBe(pngBytes());
});
