<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

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
            ->whereIn('key', ['notice_isp_name', 'notice_phone', 'notice_message', 'locale'])
            ->pluck('value', 'key');
        $phone = trim((string) ($settings['notice_phone'] ?? ''));

        // In the ISP's language: the customer is unknown here. The web middleware (and its locale) does not run.
        if (array_key_exists((string) ($settings['locale'] ?? ''), config('app.locales'))) {
            App::setLocale($settings['locale']);
        }

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
