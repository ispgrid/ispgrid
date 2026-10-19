<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\App;

/**
 * The notice text from App settings, for the pages shown to customers who cannot get online: the server's notice
 * page (disabled pool) and the Hotspot login page for IPoE devices whose IP no customer has. Both are shown in the
 * ISP's language, since neither knows who the customer is.
 */
final class NoticePage
{
    /**
     * @return array{ispName: string, phone: string, phoneLink: string, message: string, locale: ?string}
     */
    public static function settings(): array
    {
        $settings = AppSetting::query()
            ->whereIn('key', ['notice_isp_name', 'notice_phone', 'notice_message', 'locale'])
            ->pluck('value', 'key');
        $phone = trim((string) ($settings['notice_phone'] ?? ''));
        $locale = (string) ($settings['locale'] ?? '');

        return [
            'ispName' => trim((string) ($settings['notice_isp_name'] ?? '')),
            'phone' => $phone,
            'phoneLink' => (string) preg_replace('/[^0-9+]/', '', $phone),
            'message' => (string) ($settings['notice_message'] ?? ''),
            'locale' => array_key_exists($locale, config('app.locales')) ? $locale : null,
        ];
    }

    /**
     * The login.html for the IPoE Hotspot on a NAS: a self-contained page (the device cannot reach anything else)
     * that asks the customer to call the ISP.
     */
    public static function ipoeLoginPage(): string
    {
        $settings = self::settings();
        $staffLocale = App::getLocale();
        App::setLocale($settings['locale'] ?? config('app.fallback_locale'));

        try {
            $html = view('hotspot.ipoe-login', $settings)->render();
        } finally {
            App::setLocale($staffLocale);
        }

        // RouterOS reads $(name) in Hotspot pages as a variable: keep the notice text from being one.
        return str_replace('$(', '$&#40;', $html);
    }
}
