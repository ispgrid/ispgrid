<?php

namespace App\Support;

use App\Models\Nas;
use App\Rules\NotUsedByTrashed;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The NAS rules the NAS page and the setup page share.
 */
final class NasRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?int $ignoreId = null): array
    {
        return [
            'shortname' => ['required', 'string', 'max:32', 'regex:/^[\x20-\x7E]+$/D',
                Rule::unique(Nas::class, 'shortname')->withoutTrashed()->ignore($ignoreId),
                new NotUsedByTrashed(Nas::class, 'shortname', __('A deleted NAS uses this name. Restore it instead.'), $ignoreId),
            ],
            'nasname' => ['required', 'ipv4',
                Rule::unique(Nas::class, 'nasname')->withoutTrashed()->ignore($ignoreId),
                new NotUsedByTrashed(Nas::class, 'nasname', __('A deleted NAS uses this IP address. Restore it instead.'), $ignoreId),
            ],
            'secret' => ['required', 'string', 'min:8', 'max:60', 'regex:/^[A-Za-z0-9._~!@#%^*+=-]+$/D'],
            'routeros_version' => ['required', 'string', function (string $attribute, string $value, Closure $fail): void {
                if (RouterOsVersion::parse($value) === null) {
                    $fail(__('Enter the version as the NAS shows it, e.g. 7.23.7.'));
                }
            }],
            'description' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'nasname.ipv4' => __('Enter the IPv4 address the NAS sends RADIUS from, e.g. 10.0.0.1.'),
            'shortname.regex' => __('Use English letters, digits and symbols only: FreeRADIUS loads this name.'),
            'secret.regex' => __('Use letters, digits and . _ ~ ! @ # % ^ * + = - only.'),
        ];
    }
}
