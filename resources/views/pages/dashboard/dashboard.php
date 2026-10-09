<?php

use App\Models\Nas;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
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
