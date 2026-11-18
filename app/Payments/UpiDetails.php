<?php

namespace App\Payments;

use App\Billing\BillingSettings;
use App\Billing\PaymentProof;
use App\Models\AppSetting;
use Closure;
use Illuminate\Http\UploadedFile;

/**
 * The operator's own UPI details (QR picture, UPI ID, number, note) that customers pay by hand and then send a
 * screenshot of. Exists only while the Billing currency is INR; the saved values stay when the currency changes and
 * come back with INR. The QR is a file on the billing disk, shown through the public pay.upi-qr route.
 */
final readonly class UpiDetails
{
    public const DISK = 'billing';

    public const DIRECTORY = 'upi';

    /** The app setting behind the UPI switch: '1' on, '0' off. */
    public const SWITCH = 'billing.upi_enabled';

    /**
     * Pictures a QR may be, by the bytes of the file.
     */
    private const QR_EXTENSIONS = ['png', 'jpg', 'webp'];

    public function __construct(public string $id, public string $number, public ?string $qrUrl, public string $note) {}

    /**
     * The details to show customers, or null when the currency is not INR, UPI is switched off, or none of QR, ID and
     * number is set.
     */
    public static function current(): ?self
    {
        if (! self::available() || ! self::switchedOn()) {
            return null;
        }

        $id = trim((string) AppSetting::valueOf('billing.upi_id', ''));
        $number = trim((string) AppSetting::valueOf('billing.upi_number', ''));
        $qr = trim((string) AppSetting::valueOf('billing.upi_qr', ''));

        if ($id === '' && $number === '' && $qr === '') {
            return null;
        }

        return new self($id, $number, $qr === '' ? null : route('pay.upi-qr', basename($qr)), self::note());
    }

    /**
     * The UPI switch on the Quick pay page. Panels saved before the switch existed have no value: UPI stays on there,
     * so it shows whenever details are set, as it did before.
     */
    public static function switchedOn(): bool
    {
        return AppSetting::valueOf(self::SWITCH, '1') !== '0';
    }

    /**
     * UPI is offered only with Indian rupees.
     */
    public static function available(): bool
    {
        return BillingSettings::current()->currencyCode === 'INR';
    }

    /**
     * The saved note, or the default line with the notice phone (empty when there is neither).
     */
    public static function note(): string
    {
        $saved = trim((string) AppSetting::valueOf('billing.upi_note', ''));
        $phone = trim((string) AppSetting::valueOf('notice_phone', ''));

        if ($saved !== '') {
            return $saved;
        }

        return $phone === '' ? '' : __('After paying, send the screenshot on WhatsApp to :phone.', ['phone' => $phone]);
    }

    /**
     * Fails a QR upload whose bytes are not a PNG, JPEG or WebP picture, however it is named.
     */
    public static function qrRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value instanceof UploadedFile && self::qrExtension($value) === null) {
                $fail(__('The :attribute must be a PNG, JPG or WebP picture.', ['attribute' => __('QR picture')]));
            }
        };
    }

    /**
     * The extension for what the file's bytes are, or null when they are not an allowed picture.
     */
    public static function qrExtension(UploadedFile $file): ?string
    {
        $extension = PaymentProof::extensionOf($file);

        return in_array($extension, self::QR_EXTENSIONS, true) ? $extension : null;
    }
}
