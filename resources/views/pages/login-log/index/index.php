<?php

use App\Enums\LoginResult;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Nas;
use App\Models\Radpostauth;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Login log')] class extends Component
{
    use AuthorizesEveryRequest, WithPagination;

    private const FILTERS = ['username', 'result', 'reason', 'router', 'ip'];

    #[Url]
    public string $username = '';

    /**
     * '' (every attempt) or a LoginResult value.
     */
    #[Url]
    public string $result = '';

    #[Url]
    public string $reason = '';

    #[Url]
    public string $router = '';

    /**
     * The IP a login was given, matched exactly.
     */
    #[Url]
    public string $ip = '';

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('view-login-log');
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
        }
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function results(): array
    {
        return collect(LoginResult::cases())
            ->mapWithKeys(fn (LoginResult $result): array => [$result->value => $result->label()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function routers(): array
    {
        return Nas::names();
    }

    /**
     * Newest first. radpostauth grows by every attempt of every router, so the pages are not counted.
     */
    #[Computed]
    public function logins(): Paginator
    {
        $username = trim($this->username);
        $reason = trim($this->reason);
        $ip = trim($this->ip);
        $result = LoginResult::tryFrom($this->result);

        return Radpostauth::query()
            ->when($username !== '', fn (Builder $query) => $query->where('username', 'like', "{$username}%"))
            ->when($result !== null, fn (Builder $query) => $query->where('reply', $result->reply()))
            ->when($reason !== '', fn (Builder $query) => $query->where('reply_msg', 'like', "%{$reason}%"))
            ->when($this->router !== '', fn (Builder $query) => $query->where('nasipaddress', $this->router))
            ->when($ip !== '', fn (Builder $query) => $query->where('framedipaddress', $ip))
            ->orderByDesc('id')
            ->simplePaginate(50);
    }

    /**
     * @return list<array{id: int, username: string, account: ?int, when: string, result: string, color: string, reason: string, router: string, mac: string, ip: string}>
     */
    #[Computed]
    public function rows(): array
    {
        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';
        $routers = $this->routers;
        $logins = collect($this->logins->items());
        $accounts = Account::query()->whereIn('username', $logins->pluck('username')->unique())->pluck('id', 'username');

        return $logins
            ->map(fn (Radpostauth $login): array => $login->forTable($routers, $format) + ['account' => $accounts[$login->username] ?? null])
            ->all();
    }
};
