<?php

namespace App\Support;

use App\Enums\ValidityUnit;
use App\Models\Plan;
use App\Models\Pool;
use App\Rules\NotUsedByTrashed;
use Illuminate\Validation\Rule;

/**
 * The plan rules the plan form and the plan import share, so a row is accepted exactly when the form would
 * accept the same values. Speeds still need RateLimit for their format.
 */
final class PlanRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $isNew, ValidityUnit $unit = ValidityUnit::Days): array
    {
        return [
            'slug' => $isNew ? ['required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/D',
                Rule::unique(Plan::class, 'slug')->withoutTrashed(),
                new NotUsedByTrashed(Plan::class, 'slug', __('A deleted plan uses this slug. Restore it instead.')),
            ] : [],
            'name' => ['required', 'string', 'max:255'],
            'validity' => ['required', 'integer', 'between:1,'.$unit->max()],
            'price' => ['required', 'numeric', 'decimal:0,2', 'between:0,9999999999.99'],
            'description' => ['nullable', 'string', 'max:1000'],
            'upload_speed' => ['required', 'string', 'max:20'],
            'download_speed' => ['required', 'string', 'max:20'],
            'pool_id' => ['nullable', 'integer', Rule::exists(Pool::class, 'id')->withoutTrashed()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'slug.regex' => __('Use lower-case letters, digits, - and _ only.'),
            'pool_id.exists' => __('This pool was deleted. Choose another one.'),
        ];
    }
}
