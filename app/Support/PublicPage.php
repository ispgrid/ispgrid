<?php

namespace App\Support;

use App\Billing\BillingSettings;
use App\Models\AppSetting;
use Illuminate\Support\Facades\Storage;

/**
 * What the public frame (layouts/public: quick pay and Selfcare) shows around its card: the ISP's name and logo from
 * the billing settings, and the notice phone.
 */
final class PublicPage
{
    /**
     * @return array{company: string, logo: ?string, phone: string, phoneLink: string}
     */
    public static function data(): array
    {
        $settings = BillingSettings::current();
        $phone = trim((string) AppSetting::valueOf('notice_phone', ''));
        $disk = Storage::disk('billing');
        $logo = $settings->logo !== '' && $disk->exists($settings->logo)
            ? 'data:'.$disk->mimeType($settings->logo).';base64,'.base64_encode((string) $disk->get($settings->logo))
            : null;

        return [
            'company' => $settings->company->name,
            'logo' => $logo,
            'phone' => $phone,
            'phoneLink' => (string) preg_replace('/[^0-9+]/', '', $phone),
        ];
    }
}
