<?php

use Illuminate\Support\Facades\File;

/**
 * The receipt reached a customer with a black tile where the crest and the
 * coin should have been: the templates pointed at the storefront's WebP, and
 * image proxies flatten WebP alpha onto black. Nothing in the suite noticed,
 * because nothing asserted what the mail templates actually reference.
 */

/** @return list<string> every image path referenced by a mail template or mailable */
function mailImagePaths(): array
{
    $sources = array_merge(
        File::allFiles(resource_path('views/vendor/mail')),
        File::allFiles(resource_path('views/mail')),
        File::allFiles(app_path('Notifications')),
    );

    $paths = [];

    foreach ($sources as $file) {
        preg_match_all("#/images/[A-Za-z0-9_/.\-]+#", $file->getContents(), $matches);
        foreach ($matches[0] as $path) {
            $paths[$path] = true;
        }
    }

    return array_keys($paths);
}

test('every image a mail template references exists', function (): void {
    // Asserted first: a regex that stops matching would otherwise turn all
    // three of these tests green while checking nothing.
    expect(mailImagePaths())->not->toBeEmpty();

    $missing = array_values(array_filter(
        mailImagePaths(),
        fn (string $path): bool => ! File::exists(public_path(ltrim($path, '/'))),
    ));

    expect($missing)->toBe([]);
});

test('no mail template serves WebP, because proxies flatten its alpha to black', function (): void {
    $webp = array_values(array_filter(
        mailImagePaths(),
        fn (string $path): bool => str_ends_with(strtolower($path), '.webp'),
    ));

    expect($webp)->toBe([]);
});

test('the mail images keep their transparency', function (): void {
    $opaque = [];

    foreach (mailImagePaths() as $path) {
        $file = public_path(ltrim($path, '/'));

        if (! File::exists($file) || ! str_ends_with(strtolower($path), '.png')) {
            continue;
        }

        $image = imagecreatefrompng($file);
        $alpha = (imagecolorat($image, 0, 0) >> 24) & 0x7F;
        imagedestroy($image);

        if ($alpha !== 127) {
            $opaque[] = $path;
        }
    }

    expect($opaque)->toBe([]);
});
