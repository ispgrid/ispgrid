<?php

namespace App\Support\Addons;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sends an add-on's stored file to staff the add-on has already allowed (check the permission first, then call
 * this). The browser shows a file itself only when asked and only for a picture or a PDF, judged by the file's
 * bytes, never by its name; everything else (HTML, SVG, anything unknown) is downloaded, so an uploaded page can
 * never run in the panel.
 */
final class PrivateFile
{
    /**
     * Types the browser may show. Pictures open in a sandbox (no script, no forms); a PDF does not, because
     * Chromium's PDF viewer does not open in a sandboxed page.
     *
     * @var array<string, bool> type => sandboxed
     */
    private const INLINE = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/webp' => true,
        'image/gif' => true,
        'application/pdf' => false,
    ];

    /**
     * @param  string  $path  the file's path on the disk, e.g. "documents/12/aadhaar-3.pdf"
     * @param  string  $downloadName  the file name the browser offers
     * @param  bool  $inline  show it in the browser when its type allows that
     */
    public static function response(string $path, string $downloadName, bool $inline = false, string $disk = 'addons'): StreamedResponse
    {
        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);

        $type = (string) $storage->mimeType($path);
        $shown = $inline && array_key_exists($type, self::INLINE);
        $headers = [
            'Content-Type' => $shown ? $type : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];

        if ($shown && self::INLINE[$type]) {
            $headers['Content-Security-Policy'] = 'sandbox';
        }

        return $storage->response($path, $downloadName, $headers, $shown ? 'inline' : 'attachment');
    }
}
