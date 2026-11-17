<?php

namespace App\Billing;

use Closure;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The screenshot kept with a manual payment (a UPI screenshot, a cheque photo), on the private billing disk at
 * payments/{id}/proof.{ext}. Both the check and the extension come from the file's bytes, never from its name or the
 * type the browser claimed. It is shown only through PrivateFile, which judges by bytes too.
 */
final class PaymentProof
{
    public const DISK = 'billing';

    /**
     * The rule list for an upload field.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120', self::bytesRule()];
    }

    /**
     * Fails an upload whose bytes are not a JPEG, PNG, WebP or PDF, however it is named.
     */
    public static function bytesRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value instanceof UploadedFile && self::extensionOf($value) === null) {
                $fail(__('The :attribute must be a picture (JPG, PNG, WebP) or a PDF.', ['attribute' => __('screenshot')]));
            }
        };
    }

    /**
     * Keeps the file for this payment and returns its path: payments/{id}/proof.{ext}, or with $unique a name that
     * never overwrites an earlier file (a replacement, so the old file can go only after the change is saved).
     */
    public static function store(int $paymentId, UploadedFile $file, bool $unique = false): string
    {
        $extension = self::extensionOf($file);

        if ($extension === null) {
            throw new InvalidArgumentException('The screenshot must be a JPEG, PNG, WebP or PDF.');
        }

        $name = ($unique ? 'proof-'.Str::random(16) : 'proof').'.'.$extension;
        $stored = Storage::disk(self::DISK)->putFileAs("payments/{$paymentId}", $file, $name);

        if ($stored === false) {
            throw new RuntimeException('The screenshot could not be stored.');
        }

        return $stored;
    }

    public static function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * The extension for what the file's bytes are, or null when they are not an allowed type.
     */
    public static function extensionOf(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            return null;
        }

        return match ((new finfo(FILEINFO_MIME_TYPE))->file($path)) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => null,
        };
    }
}
