<?php

use Illuminate\Support\Facades\File;

it('uses no Flux anywhere in the views', function () {
    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file) => preg_match('/<flux:|@flux|Flux::|Flux\\\\/', $file->getContents()) === 1)
        ->map(fn (SplFileInfo $file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('keeps every Livewire page a multi-file component', function () {
    $singleFile = collect(File::allFiles(resource_path('views/pages')))
        ->filter(fn (SplFileInfo $file) => str_ends_with($file->getFilename(), '.blade.php')
            && str_starts_with(ltrim($file->getContents()), '<?php'))
        ->map(fn (SplFileInfo $file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($singleFile)->toBe([]);
});
