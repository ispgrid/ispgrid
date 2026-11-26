<?php

namespace App\Payments;

use App\Notifications\SettingField;

/**
 * The Environment setting every gateway asks for: a list of Test and Live, saved as test or live.
 */
final class EnvironmentField
{
    public static function make(string $help): SettingField
    {
        return new SettingField('environment', __('Environment'), type: 'select', help: $help, options: ['test' => __('Test'), 'live' => __('Live')]);
    }
}
