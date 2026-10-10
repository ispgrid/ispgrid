<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Nas;
use App\Models\Radacct;
use App\Models\Radpostauth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    /**
     * Customers online now (open sessions, each customer once) and Active customers by expiry (spec section 5).
     *
     * @return array{online: int, active: int, expiring: int, expired: int}
     */
    #[Computed]
    public function figures(): array
    {
        $active = fn () => Account::query()->where('status_id', AccountStatus::Active);

        return [
            'online' => Radacct::query()->open()->distinct()->count('username'),
            'active' => $active()->count(),
            'expiring' => $active()->whereBetween('expiry', [now(), now()->addDays(7)])->count(),
            'expired' => $active()->where('expiry', '<', now())->count(),
        ];
    }

    /**
     * The last 10 refused or dropped login attempts.
     *
     * @return list<array{id: int, username: string, when: string, result: string, color: string, reason: string, router: string, mac: string}>
     */
    #[Computed]
    public function failedLogins(): array
    {
        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';
        $routers = Nas::names();

        return Radpostauth::query()->where('reply', '!=', 'Access-Accept')->orderByDesc('id')->limit(10)->get()
            ->map(fn (Radpostauth $login): array => $login->forTable($routers, $format))
            ->all();
    }

    /**
     * Live routers whose RouterOS is too old to send Message-Authenticator (spec section 5).
     *
     * @return list<array{id: int, router: string, warning: string}>
     */
    #[Computed]
    public function outdatedRouters(): array
    {
        return Nas::query()
            ->orderBy('shortname')
            ->get()
            ->filter(fn (Nas $router): bool => $router->versionWarning() !== null)
            ->map(fn (Nas $router): array => [
                'id' => $router->id,
                'router' => ($router->shortname ?? $router->nasname).' ('.$router->nasname.')',
                'warning' => $router->versionWarning(),
            ])
            ->values()
            ->all();
    }
};
