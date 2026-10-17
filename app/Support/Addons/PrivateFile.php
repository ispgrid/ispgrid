<?php

namespace App\Support\Addons;

use finfo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
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

        [$headers, $shown] = self::headers((string) $storage->mimeType($path), $inline);

        return $storage->response($path, $downloadName, $headers, $shown ? 'inline' : 'attachment');
    }

    /**
     * The same as response(), for bytes the add-on already holds, such as a file it keeps encrypted and has just
     * decrypted. The type is judged from the bytes.
     *
     * @param  string  $contents  the file itself
     * @param  string  $downloadName  the file name the browser offers
     * @param  bool  $inline  show it in the browser when its type allows that
     */
    public static function contentResponse(string $contents, string $downloadName, bool $inline = false): Response
    {
        [$headers, $shown] = self::headers((string) (new finfo(FILEINFO_MIME_TYPE))->buffer($contents), $inline);
        $headers['Content-Disposition'] = HeaderUtils::makeDisposition(
            $shown ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $downloadName,
            str_replace(['/', '\\', '%'], '', Str::ascii($downloadName)) ?: 'file',
        );

        return response($contents, 200, $headers);
    }

    /**
     * The headers for a file of this type, and whether the browser may show it.
     *
     * @return array{0: array<string, string>, 1: bool}
     */
    private static function headers(string $type, bool $inline): array
    {
        $shown = $inline && array_key_exists($type, self::INLINE);
        $headers = [
            'Content-Type' => $shown ? $type : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];

        if ($shown && self::INLINE[$type]) {
            $headers['Content-Security-Policy'] = 'sandbox';
        }

        return [$headers, $shown];
    }
}
