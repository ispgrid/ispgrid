<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Payments\UpiDetails;
use App\Support\Addons\PrivateFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The operator's UPI QR picture for the pay page. Public, but only the file saved right now and only with INR:
 * the file name is matched exactly against the setting, so nothing else on the disk can be reached.
 */
class UpiQrController extends Controller
{
    public function __invoke(string $file): StreamedResponse
    {
        $saved = (string) AppSetting::valueOf('billing.upi_qr', '');
        abort_unless(UpiDetails::available() && $saved !== '' && $saved === UpiDetails::DIRECTORY.'/'.$file, 404);

        $response = PrivateFile::response($saved, $file, true, UpiDetails::DISK);
        $response->headers->set('Cache-Control', 'public, max-age=86400');

        return $response;
    }
}
