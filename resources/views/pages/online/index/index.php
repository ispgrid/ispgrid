<?php

use App\Jobs\DisconnectAccount;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Nas;
use App\Models\Radacct;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Online')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts, WithPagination;

    #[Url]
    public string $search = '';

    /**
     * A router IP (radacct.nasipaddress), or '' for every router.
     */
    #[Url]
    public string $router = '';

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('view-online-sessions');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'router'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function routers(): array
    {
        return Nas::names();
    }

    #[Computed]
    public function sessions(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Radacct::query()->open()
            ->when($this->router !== '', fn (Builder $query) => $query->where('nasipaddress', $this->router))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('username', 'like', "{$search}%")
                ->orWhere('framedipaddress', $search)
                ->orWhere('callingstationid', $search)))
            ->orderBy('username')
            ->paginate(50);
    }

    /**
     * @return list<array{id: int, username: string, account: ?int, router: string, ip: string, mac: string, started: string, duration: string, download: string, upload: string}>
     */
    #[Computed]
    public function rows(): array
    {
        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';
        $routers = $this->routers;
        $sessions = collect($this->sessions->items());
        $accounts = Account::query()->whereIn('username', $sessions->pluck('username')->unique())->pluck('id', 'username');

        return $sessions
            ->map(fn (Radacct $session): array => $session->forTable($routers, $format) + ['account' => $accounts[$session->username] ?? null])
            ->all();
    }

    /**
     * Sends a Disconnect-Request for one session and logs who asked for it.
     */
    public function disconnect(int $sessionId): void
    {
        $this->authorize('disconnect-sessions');
        $session = Radacct::query()->open()->find($sessionId);

        if ($session === null) {
            unset($this->sessions, $this->rows);
            $this->toast(__('This session has already ended.'));

            return;
        }

        DisconnectAccount::dispatch($session->username, $session->radacctid);

        $log = activity()->event('disconnected')->withChanges([
            'old' => [],
            'attributes' => ['sessions' => trim($session->nasipaddress.' '.$session->framedipaddress)],
        ]);
        $account = Account::withTrashed()->where('username', $session->username)->first();

        if ($account !== null) {
            $log->performedOn($account);
        }

        $log->log('disconnected');

        $this->toast(__('Disconnect sent to the NAS. The session closes when the NAS confirms.'));
    }
};
