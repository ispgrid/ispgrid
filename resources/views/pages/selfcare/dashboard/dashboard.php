<?php

use App\Http\Middleware\SelfcareSession;
use App\Models\AppSetting;
use App\Models\Radacct;
use App\Models\Radpostauth;
use App\Models\SelfcareAccount;
use App\Payments\PayCode;
use App\Payments\QuickPay;
use App\Support\Money;
use App\Support\PublicPage;
use App\Support\SelfcareConnection;
use App\Support\Units;
use App\Support\Usage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The customer's one page after login: their plan and status, Renew, the connection details for their router, and
 * links to the password pages. The customer is always the logged-in one, never a request parameter. Nothing about
 * the NAS is shown.
 */
new class extends Component
{
    #[Computed]
    public function account(): SelfcareAccount
    {
        return SelfcareSession::account() ?? abort(403);
    }

    /**
     * @return array{name: string, username: string, plan: ?string, expiry: ?string, expired: bool, status: string, color: string}
     */
    #[Computed]
    public function summary(): array
    {
        $account = $this->account;

        return [
            'name' => (string) ($account->fullname ?: $account->username),
            'username' => (string) $account->username,
            'plan' => $account->plan()->withTrashed()->value('name'),
            'expiry' => $account->expiry?->format($this->dateFormat().' H:i'),
            'expired' => $account->expiry !== null && $account->expiry->lessThanOrEqualTo(now()),
            'status' => $account->status_id->label(),
            'color' => $account->status_id->color(),
        ];
    }

    /**
     * Renew: a quick pay link with the amount, the date renewal opens, or the number to call.
     *
     * @return array{kind: 'pay'|'opens'|'call'|'none', label: string, url: ?string}
     */
    #[Computed]
    public function renew(): array
    {
        $account = $this->account;
        $phone = trim((string) AppSetting::valueOf('notice_phone', ''));

        if (! QuickPay::isOpen()) {
            return $phone === ''
                ? ['kind' => 'none', 'label' => '', 'url' => null]
                : ['kind' => 'call', 'label' => __('To renew, call us: :phone', ['phone' => $phone]), 'url' => 'tel:'.preg_replace('/[^0-9+]/', '', $phone)];
        }

        $quote = app(QuickPay::class)->quote($account);

        if ($quote !== null) {
            $amount = Money::fromSettings()->format($quote->total);

            return [
                'kind' => 'pay',
                'label' => $quote->renewal !== null ? __('Renew for :amount', ['amount' => $amount]) : __('Pay :amount', ['amount' => $amount]),
                'url' => route('pay.code', PayCode::for($account->id)),
            ];
        }

        $days = QuickPay::renewalDays();
        $opens = $days === null || $account->expiry === null ? null : $account->expiry->subDays($days);

        return $opens !== null && $opens->isFuture()
            ? ['kind' => 'opens', 'label' => __('Renewal opens on :date', ['date' => $opens->format($this->dateFormat())]), 'url' => null]
            : ['kind' => 'none', 'label' => '', 'url' => null];
    }

    /**
     * The connection details that have a value, labelled.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function connection(): array
    {
        $labels = [
            'ip' => __('IP address'),
            'mask' => __('Subnet mask'),
            'gateway' => __('Gateway'),
            'dns1' => __('DNS 1'),
            'dns2' => __('DNS 2'),
        ];
        $rows = [];

        foreach (SelfcareConnection::for($this->account) as $key => $value) {
            if ($value !== null && $value !== '') {
                $rows[$labels[$key]] = $value;
            }
        }

        return $rows;
    }

    /**
     * Data used since the last recharge and per day for the last 30 days, from the usage database FreeRADIUS checks
     * limits against. Null when there is nothing to show.
     *
     * @return ?array{period: string, days: list<array{day: string, download: string, upload: string}>}
     */
    #[Computed]
    public function usage(): ?array
    {
        $username = (string) $this->account->username;
        $period = Usage::sinceRecharge($username);
        $days = Usage::days($username, 30);

        if ($period['bytes'] === 0 && $days === []) {
            return null;
        }

        return [
            'period' => Units::bytes($period['bytes']),
            'days' => array_map(fn (array $day): array => [
                'day' => $day['day']->format($this->dateFormat()),
                'download' => Units::bytes($day['download']),
                'upload' => Units::bytes($day['upload']),
            ], $days),
        ];
    }

    /**
     * The customer's open sessions: when they started, the IP, how long and how much data. Never the NAS.
     *
     * @return list<array{started: string, ip: string, uptime: string, data: string}>
     */
    #[Computed]
    public function sessions(): array
    {
        return Radacct::query()->open()->where('username', $this->account->username)->orderBy('acctstarttime')
            ->get(['acctstarttime', 'framedipaddress', 'acctinputoctets', 'acctoutputoctets'])
            ->map(fn (Radacct $session): array => [
                'started' => $session->acctstarttime->format($this->dateFormat().' H:i'),
                'ip' => (string) $session->framedipaddress,
                'uptime' => Units::duration((int) $session->acctstarttime->diffInSeconds(now())),
                'data' => Units::bytes((int) $session->acctinputoctets + (int) $session->acctoutputoctets),
            ])->all();
    }

    /**
     * The last 10 login attempts: when and the result in words. No reason text, router or MAC.
     *
     * @return list<array{when: string, result: string, color: string}>
     */
    #[Computed]
    public function logins(): array
    {
        return Radpostauth::query()->where('username', $this->account->username)->orderByDesc('authdate')->orderByDesc('id')->limit(10)
            ->get(['id', 'authdate', 'reply'])
            ->map(fn (Radpostauth $login): array => [
                'when' => $login->authdate->format($this->dateFormat().' H:i'),
                'result' => $login->result()->label(),
                'color' => $login->result()->color(),
            ])->all();
    }

    public function render(): View
    {
        return $this->view()->layout('layouts::public', [...PublicPage::data(), 'title' => __('Selfcare')]);
    }

    private function dateFormat(): string
    {
        return (string) AppSetting::valueOf('date_format', 'd/m/Y');
    }
};
