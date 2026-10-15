<?php

use App\Enums\SlotMode;
use App\Models\Account;
use App\Models\AddressList;
use App\Models\AppSetting;
use App\Models\Plan;
use App\Models\PlanAttribute;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Plan')] class extends Component
{
    use WithPagination;

    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    #[Locked]
    public int $planId;

    public function mount(int $plan): void
    {
        $this->authorize('viewAny', Plan::class);
        abort_unless(Plan::withTrashed()->whereKey($plan)->exists(), 404);
        $this->planId = $plan;
    }

    #[Computed]
    public function plan(): Plan
    {
        return Plan::withTrashed()->with(['pool' => fn ($query) => $query->withTrashed(), 'addressLists', 'radiusAttributes'])->findOrFail($this->planId);
    }

    /**
     * What the plan gives, as label => value, leaving out what it does not set.
     *
     * @return array<string, array<string, string>>
     */
    #[Computed]
    public function details(): array
    {
        $plan = $this->plan;
        $pair = fn (?string $upload, ?string $download): ?string => $upload === null && $download === null ? null : ($upload ?? '-').'/'.($download ?? '-');
        $gb = fn (?int $value): ?string => $value === null ? null : __(':count GB', ['count' => $value]);
        $hours = fn (?int $value): ?string => $value === null ? null : trans_choice(':count hour|:count hours', $value);

        $sections = [
            __('General') => [
                __('Slug') => $plan->slug,
                __('Speed (up/down)') => $plan->upload.'/'.$plan->download,
                __('Validity') => $plan->validityLabel(),
                __('Price') => Money::fromSettings()->format((string) $plan->price),
                __('Pool') => $plan->pool?->name,
                __('Address lists') => $plan->addressLists->sortBy('name')->map(fn (AddressList $list): string => $list->name)->implode(', '),
                __('Description') => $plan->description,
            ],
            __('Speed') => [
                __('Burst (up/down)') => $pair($plan->burst_upload, $plan->burst_download),
                __('Threshold (up/down)') => $pair($plan->threshold_upload, $plan->threshold_download),
                __('Burst time (seconds)') => $plan->burst_time === null ? null : (string) $plan->burst_time,
                __('Priority') => $plan->priority === null ? null : (string) $plan->priority,
                __('Minimum (up/down)') => $pair($plan->min_upload, $plan->min_download),
            ],
            __('Limits') => [
                __('Total data (GB)') => $plan->limit_after === null ? null : (string) $plan->limit_after,
                __('Total time') => $hours($plan->time_limit_hours),
                __('Data per day') => $gb($plan->daily_data_gb),
                __('Time per day') => $hours($plan->daily_time_hours),
                __('Data per month') => $gb($plan->monthly_data_gb),
                __('Time per month') => $hours($plan->monthly_time_hours),
                __('Fair usage speed (up/down)') => $plan->is_fup ? $pair($plan->fup_upload, $plan->fup_download) : null,
            ],
            __('Time slot') => $plan->slot_mode === null ? [] : [
                __('Time slot') => $plan->slot_mode === SlotMode::AccessOnly
                    ? __('Allow access only during the time slot')
                    : __('Increase speed during the time slot'),
                __('Days') => $this->slotDays($plan->slot_days),
                __('Hours') => $plan->slot_from === null || $plan->slot_to === null ? null : sprintf('%02d:00 - %02d:00', $plan->slot_from, $plan->slot_to),
                __('Speed during the slot (up/down)') => $plan->slot_mode === SlotMode::Boost ? $pair($plan->slot_upload, $plan->slot_download) : null,
            ],
            __('RADIUS attributes') => $plan->radiusAttributes->sortBy('id')
                ->mapWithKeys(fn (PlanAttribute $attribute): array => [$attribute->attribute => $attribute->value])
                ->all(),
        ];

        return array_filter(
            array_map(fn (array $rows): array => array_filter($rows, fn (?string $value): bool => $value !== null && $value !== ''), $sections),
            fn (array $rows): bool => $rows !== [],
        );
    }

    #[Computed]
    public function customerCount(): int
    {
        return Account::query()->where('plan_id', $this->planId)->count();
    }

    #[Computed]
    public function canSeeCustomers(): bool
    {
        return Gate::allows('viewAny', Account::class);
    }

    #[Computed]
    public function accounts(): LengthAwarePaginator
    {
        return Account::query()
            ->where('plan_id', $this->planId)
            ->orderBy('username')
            ->paginate(25);
    }

    /**
     * @return list<array{id: int, username: string, fullname: string, type: string, status: string, color: string, expiry: ?string, pending: bool, expired: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';

        return collect($this->accounts->items())->map(fn (Account $account): array => [
            'id' => $account->id,
            'username' => $account->username,
            'fullname' => $account->fullname,
            'type' => $account->service_type->label(),
            'status' => $account->status_id->label(),
            'color' => $account->status_id->color(),
            'expiry' => $account->expiry?->format($format),
            'pending' => $account->hotspot_pending_validity !== null,
            'expired' => $account->expiry?->isPast() ?? false,
        ])->all();
    }

    private function slotDays(?string $days): ?string
    {
        if ($days === null || $days === '') {
            return null;
        }

        $labels = array_combine(self::DAYS, [__('Monday'), __('Tuesday'), __('Wednesday'), __('Thursday'),
            __('Friday'), __('Saturday'), __('Sunday')]);

        return collect(explode(',', $days))->map(fn (string $day): string => $labels[$day] ?? $day)->implode(', ');
    }
};
