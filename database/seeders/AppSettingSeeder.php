<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use Illuminate\Database\Seeder;

class AppSettingSeeder extends Seeder
{
    /**
     * Defaults for a new install (India). Values the operator changed are kept.
     */
    public function run(): void
    {
        $defaults = [
            'currency_code' => 'INR',
            'currency_symbol' => '₹',
            'currency_decimals' => '2',
            'number_grouping' => 'indian',
            'date_format' => 'd/m/Y',
            'phone_country_code' => '+91',
            'phone_digits' => '10',
            'tax_id_label' => 'GSTIN',
            'business_id_label' => 'PAN',
            'expiry_mode' => 'exact',
            'notice_isp_name' => '',
            'notice_phone' => '',
            'notice_message' => 'Your account is inactive or expired. Please contact us to renew.',
        ];

        foreach ($defaults as $key => $value) {
            AppSetting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
