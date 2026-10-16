<?php

use App\Enums\NotificationStatus;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\NotificationProvider;
use App\Models\NotificationRule;
use App\Notifications\NotificationRegistry;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notification rules')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('manage-notification-rules');
    }

    /**
     * @return list<array{id: int, name: string, trigger: string, channel: string, provider: string, enabled: bool, sent: int}>
     */
    #[Computed]
    public function rules(): array
    {
        $registry = app(NotificationRegistry::class);

        return NotificationRule::query()
            ->withCount(['logs as sent_count' => fn (Builder $query) => $query->where('status', NotificationStatus::Sent)->where('created_at', '>=', now()->subDays(30))])
            ->orderBy('name')
            ->get()
            ->map(function (NotificationRule $rule) use ($registry): array {
                $trigger = $registry->triggers()[$rule->trigger] ?? null;
                $provider = $registry->providerClass($rule->provider);

                return [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'trigger' => $trigger === null ? $rule->trigger : $trigger::summary($rule->trigger_settings ?? []),
                    'channel' => $provider === null ? '' : $provider::channel()->label(),
                    'provider' => $provider === null ? $rule->provider : $provider::label(),
                    'enabled' => $rule->enabled,
                    'sent' => (int) $rule->sent_count,
                ];
            })
            ->all();
    }

    public function toggle(int $ruleId): void
    {
        $this->authorize('manage-notification-rules');
        $rule = NotificationRule::query()->findOrFail($ruleId);

        if (! $rule->enabled && ! in_array($rule->provider, NotificationProvider::enabledKeys(), true)) {
            $this->toast(__('Switch the provider on first, under Providers.'), 'danger');

            return;
        }

        $rule->update(['enabled' => ! $rule->enabled]);
        $this->toast($rule->enabled ? __('Rule switched on.') : __('Rule switched off.'));
    }

    public function delete(int $ruleId): void
    {
        $this->authorize('manage-notification-rules');
        NotificationRule::query()->findOrFail($ruleId)->delete();

        $this->toast(__('Rule deleted.'));
    }
};
