<?php

use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\RestartsFreeRadius;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Pool;
use App\Models\RadiusSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('FreeRADIUS settings')] class extends Component
{
    use AuthorizesEveryRequest, RestartsFreeRadius, ShowsToasts;

    public string $expire_type = '';

    public string $disabled_users_pool = '';

    public string $default_session_timeout = '';

    public string $reject_session_timeout = '';

    public bool $count_and_reject = false;

    public string $reject_requests = '';

    public string $reject_packet_interval = '';

    public function mount(): void
    {
        $this->authorizeAccess();

        $settings = RadiusSetting::current();

        $this->expire_type = (string) $settings->expire_type;
        $this->disabled_users_pool = (string) $settings->disabled_users_pool;
        $this->default_session_timeout = (string) $settings->default_session_timeout;
        $this->reject_session_timeout = (string) $settings->reject_session_timeout;
        $this->count_and_reject = (bool) $settings->count_and_reject;
        $this->reject_requests = (string) $settings->reject_requests;
        $this->reject_packet_interval = (string) $settings->reject_packet_interval;
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('manage-radius-settings');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'expire_type' => ['required', 'in:0,1'],
            'disabled_users_pool' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/D'],
            'default_session_timeout' => ['required', 'integer', 'between:300,604800'],
            'reject_session_timeout' => ['required', 'integer', 'between:60,65535'],
            'count_and_reject' => ['boolean'],
            'reject_requests' => ['required', 'integer', 'between:1,1000'],
            'reject_packet_interval' => ['required', 'integer', 'between:1,1440'],
        ];
    }

    /**
     * Validates everything first, saves the one row, and restarts FreeRADIUS after the commit (it caches the
     * settings for 24 hours; the restart clears the cache).
     */
    public function save(): void
    {
        $this->authorize('manage-radius-settings');

        $validated = $this->validate(messages: [
            'disabled_users_pool.regex' => __('Use letters, digits and . _ - only, as on the NAS.'),
        ]);

        $settings = RadiusSetting::current();

        DB::transaction(fn () => $settings->update([
            'expire_type' => (int) $validated['expire_type'],
            'disabled_users_pool' => $validated['disabled_users_pool'],
            'default_session_timeout' => (int) $validated['default_session_timeout'],
            'reject_session_timeout' => (int) $validated['reject_session_timeout'],
            'count_and_reject' => $validated['count_and_reject'] ? 1 : 0,
            'reject_requests' => (int) $validated['reject_requests'],
            'reject_packet_interval' => (int) $validated['reject_packet_interval'],
        ]), attempts: 3);

        if (! $settings->wasChanged()) {
            $this->toast(__('Nothing changed.'));

            return;
        }

        $this->restartFreeRadiusAfter(__('FreeRADIUS settings saved.'));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function expireTypes(): array
    {
        return [
            '1' => __('Let them connect on the disabled pool (they see the notice page)'),
            '0' => __('Reject the login'),
        ];
    }

    /**
     * True when no pool in Network > Pools has the disabled pool's name (routers need that pool).
     */
    #[Computed]
    public function poolIsMissing(): bool
    {
        return $this->disabled_users_pool !== ''
            && ! Pool::query()->where('name', $this->disabled_users_pool)->exists();
    }
};
