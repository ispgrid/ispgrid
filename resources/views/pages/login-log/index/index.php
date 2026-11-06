<?php

use App\Enums\LoginResult;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Nas;
use App\Models\Radpostauth;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Login log')] class extends Component
{
    use AuthorizesEveryRequest, WithPagination;

    private const FILTERS = ['username', 'result', 'reason', 'router', 'ip', 'grouped'];

    private const PER_PAGE = 50;

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

    /**
     * Repeats of one attempt (the same username, device, NAS, result and reason in a row) shown as one line with a
     * count, so a router that retries every second fills one line, not thousands.
     */
    #[Url(except: true)]
    public bool $grouped = true;

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
     * Newest first, with numbered pages. Counting and grouping read at most the newest radius.login_log_cap matching
     * attempts, so a page change stays cheap however large radpostauth grows.
     */
    #[Computed]
    public function logins(): LengthAwarePaginator
    {
        if (! $this->grouped) {
            return $this->filtered()->orderByDesc('id')->paginate(self::PER_PAGE, total: min($this->total, self::cap()));
        }

        $newest = $this->filtered()->orderByDesc('id')->limit(self::cap())
            ->select(['id', 'username', 'reply', 'reply_msg', 'authdate', 'nasipaddress', 'mac', 'framedipaddress'])
            ->toBase();
        // A run of the same refused attempt from one device is one island; every accept stays its own line.
        $islands = DB::query()->fromSub($newest, 'newest')->selectRaw(
            "newest.*, CASE WHEN reply = 'Access-Accept' THEN -id ELSE "
            .'ROW_NUMBER() OVER (PARTITION BY username, mac, nasipaddress ORDER BY id) '
            .'- ROW_NUMBER() OVER (PARTITION BY username, mac, nasipaddress, reply, reply_msg ORDER BY id) END AS island'
        );

        return Radpostauth::query()->fromSub($islands, 'radpostauth')
            ->selectRaw('MAX(id) AS id, username, reply, reply_msg, nasipaddress, mac, MAX(framedipaddress) AS framedipaddress, '
                .'MAX(authdate) AS authdate, MIN(authdate) AS first_authdate, COUNT(*) AS repeats')
            ->groupBy('username', 'reply', 'reply_msg', 'nasipaddress', 'mac', 'island')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * The matching attempts, counted up to the cap plus one (more than the cap shows as "more than").
     */
    #[Computed]
    public function total(): int
    {
        return DB::query()->fromSub($this->filtered()->select('id')->limit(self::cap() + 1)->toBase(), 'counted')->count();
    }

    /**
     * "36,109 attempts", or "More than 100,000 attempts" past the cap.
     */
    #[Computed]
    public function summary(): string
    {
        return $this->total > self::cap()
            ? __('More than :count attempts', ['count' => number_format(self::cap())])
            : trans_choice(':count attempt|:count attempts', $this->total, ['count' => number_format($this->total)]);
    }

    /**
     * @return Builder<Radpostauth>
     */
    private function filtered(): Builder
    {
        $username = trim($this->username);
        $reason = trim($this->reason);
        $ip = trim($this->ip);
        $result = LoginResult::tryFrom($this->result);

        return Radpostauth::query()
            ->when($username !== '', fn (Builder $query) => $query->where('username', 'like', "{$username}%"))
            ->when($result !== null, fn (Builder $query) => $result->scope($query))
            ->when($reason !== '', fn (Builder $query) => $query->where('reply_msg', 'like', "%{$reason}%"))
            ->when($this->router !== '', fn (Builder $query) => $query->where('nasipaddress', $this->router))
            ->when($ip !== '', fn (Builder $query) => $query->where('framedipaddress', $ip));
    }

    private static function cap(): int
    {
        return max(1, (int) config('radius.login_log_cap'));
    }

    /**
     * @return list<array{id: int, username: string, account: ?int, when: string, result: string, color: string, reason: string, router: string, mac: string, ip: string, repeats: int, since: ?string}>
     */
    #[Computed]
    public function rows(): array
    {
        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';
        $routers = $this->routers;
        $logins = collect($this->logins->items());
        $accounts = Account::query()->whereIn('username', $logins->pluck('username')->unique())->pluck('id', 'username');

        return $logins
            ->map(function (Radpostauth $login) use ($routers, $format, $accounts): array {
                $repeats = (int) ($login->getAttribute('repeats') ?? 1);
                $first = $login->getAttribute('first_authdate');

                return $login->forTable($routers, $format) + [
                    'account' => $accounts[$login->username] ?? null,
                    'repeats' => $repeats,
                    'since' => $repeats > 1 && $first !== null ? Carbon::parse((string) $first)->format($format) : null,
                ];
            })
            ->all();
    }
};
