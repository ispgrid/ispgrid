<?php

namespace App\Support;

use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;

/**
 * The plans a dialog offers a customer, with their validity and price, by id. First-login plans only for Hotspot
 * customers (nobody else logs in on the Hotspot page, so the validity would never start), and never for a change
 * of plan or an advance renewal, which need a date to start from.
 */
final class PlanChoices
{
    /**
     * @return array<int, string>
     */
    public static function for(Account $account, bool $firstLogin = true): array
    {
        $money = Money::fromSettings();
        $offersFirstLogin = $firstLogin && $account->service_type === ServiceType::Hotspot;

        return Plan::query()->orderBy('name')
            ->when(! $offersFirstLogin, fn (Builder $query) => $query->where('hotspot_starts_at_first_login', false))
            ->get()
            ->mapWithKeys(fn (Plan $plan): array => [$plan->id => __(':name (:validity, :price)', [
                'name' => $plan->name,
                'validity' => $plan->validityLabel(),
                'price' => $money->format((string) $plan->price),
            ])])
            ->all();
    }
}
