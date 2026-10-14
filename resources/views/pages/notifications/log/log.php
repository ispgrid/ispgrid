<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\AppSetting;
use App\Models\NotificationLog;
use App\Models\NotificationRule;
use App\Notifications\Notifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Message log')] class extends Component
{
    use ShowsToasts, WithPagination;

    #[Url(as: 'customer')]
    public string $customer = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $channel = '';

    #[Url(as: 'rule')]
    public string $ruleId = '';

    public function mount(): void
    {
        $this->authorize('view-notification-log');
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        return NotificationLog::query()
            ->with(['account:id,username', 'rule:id,name'])
            ->when($this->customer !== '', fn (Builder $query) => $query->whereHas('account', fn (Builder $account) => $account->withTrashed()->where('username', 'like', '%'.$this->customer.'%')))
            ->when(NotificationStatus::tryFrom($this->status), fn (Builder $query, NotificationStatus $status) => $query->where('status', $status))
            ->when(NotificationChannel::tryFrom($this->channel), fn (Builder $query, NotificationChannel $channel) => $query->where('channel', $channel))
            ->when($this->ruleId !== '', fn (Builder $query) => $query->where('notification_rule_id', (int) $this->ruleId))
            ->latest('id')
            ->paginate(50);
    }

    /**
     * @return list<array{id: int, when: string, customer: ?string, accountId: ?int, rule: string, channel: string, to: string, status: string, color: string, error: ?string, preview: ?string, canRetry: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';

        return collect($this->logs->items())->map(fn (NotificationLog $log): array => [
            'id' => $log->id,
            'when' => $log->created_at->format($format),
            'customer' => $log->account?->username,
            'accountId' => $log->account_id,
            'rule' => $log->rule?->name ?? __('Test'),
            'channel' => $log->channel->label(),
            'to' => $log->to,
            'status' => $log->status->label(),
            'color' => $log->status->color(),
            'error' => $log->error,
            'preview' => $log->preview ?? implode(' | ', $log->values ?? []),
            'canRetry' => $log->status === NotificationStatus::Failed,
        ])->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function statusOptions(): array
    {
        return collect(NotificationStatus::cases())->mapWithKeys(fn (NotificationStatus $status): array => [$status->value => $status->label()])->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function channelOptions(): array
    {
        return collect(NotificationChannel::cases())->mapWithKeys(fn (NotificationChannel $channel): array => [$channel->value => $channel->label()])->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function ruleOptions(): array
    {
        return NotificationRule::withTrashed()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function retry(int $logId, Notifier $notifier): void
    {
        $this->authorize('manage-notification-rules');
        $log = NotificationLog::query()->where('status', NotificationStatus::Failed)->findOrFail($logId);

        $notifier->retry($log);
        $this->toast(__('Message queued again.'));
    }
};
