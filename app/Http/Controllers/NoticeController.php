<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\Response;

/**
 * The page customers in the disabled pool see (spec section 5): the router sends their web traffic here. It cannot
 * know who the customer is (requests arrive from the router), so it shows the ISP's notice text from App settings.
 * No session, cookie or asset: every request of every disabled customer lands here.
 */
class NoticeController extends Controller
{
    public function __invoke(): Response
    {
        $settings = AppSetting::query()
            ->whereIn('key', ['notice_isp_name', 'notice_phone', 'notice_message'])
            ->pluck('value', 'key');
        $phone = trim((string) ($settings['notice_phone'] ?? ''));

        return response()
            ->view('notice', [
                'ispName' => trim((string) ($settings['notice_isp_name'] ?? '')),
                'phone' => $phone,
                'phoneLink' => (string) preg_replace('/[^0-9+]/', '', $phone),
                'message' => (string) ($settings['notice_message'] ?? ''),
            ])
            ->header('Cache-Control', 'no-store');
    }
}
