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
            'locale' => 'en',
            'default_pool_ppp' => '',
            'default_pool_hotspot' => '',
            'default_ip_range_ipoe' => '',
            'notice_isp_name' => '',
            'notice_phone' => '',
            'notice_message' => 'Your account is inactive or expired. Please contact us to renew.',
            'notify_hours_from' => '09:00',
            'notify_hours_to' => '21:00',
            'notify_date_format' => 'd-M-Y',
            'notify_time_format' => 'h:iA',
            'login_log_days' => '90',
        ];

        foreach ($defaults as $key => $value) {
            AppSetting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
