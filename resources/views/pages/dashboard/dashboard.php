<?php

use App\Models\AppSetting;
use App\Models\Nas;
use App\Models\Radpostauth;
use App\Models\User;
use App\Support\Dashboard\MoneyFigures;
use App\Support\Dashboard\NeedsAttention;
use App\Support\Dashboard\TodayFigures;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    /**
     * The numbers of the day, each opening the list it counts when the role can open that list.
     *
     * @return list<array{label: string, value: int, href: ?string}>
     */
    #[Computed]
    public function todayFigures(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return TodayFigures::cards($user);
    }

    /**
     * The money cards (View sales only; empty otherwise).
     *
     * @return list<array{label: string, value: string, href: ?string, hint: ?string}>
     */
    #[Computed]
    public function moneyFigures(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return MoneyFigures::cards($user);
    }

    /**
     * What needs attention, each list empty when nothing does (the view hides empty ones).
     *
     * @return array{looping: list<array{username: string, count: int, href: ?string}>, quiet: list<array{router: string, last_heard: string}>, messages: int, disconnects: list<array{username: string, reason: string, href: ?string}>, skipped: list<array{username: string, reason: string, href: ?string}>, overLimit: list<array{username: string, used: string, limit: string, href: ?string}>, ranges: list<array{range: string, used: int, total: int}>, server: ?array{backup_age_hours: ?int, backup_late: bool, jobs_waiting: int, jobs_failed: int}}
     */
    #[Computed]
    public function attention(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $attention = new NeedsAttention($user);

        return [
            'looping' => $attention->loopingLogins(),
            'quiet' => $attention->quietRouters(),
            'messages' => $attention->failedMessages(),
            'disconnects' => $attention->failedDisconnects(),
            'skipped' => $attention->skippedRenewals(),
            'overLimit' => $attention->overDataLimit(),
            'ranges' => $attention->fullRanges(),
            'server' => $attention->server(),
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
