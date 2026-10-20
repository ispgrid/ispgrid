<?php

use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Support\Addons\Addon;
use App\Support\Addons\AddonManifest;
use App\Support\Addons\AddonSettings;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Add-ons')] class extends Component
{
    use AuthorizesEveryRequest;

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        abort_unless(app(AddonSettings::class)->canOpenPage(auth()->user()), 403);
    }

    /**
     * The installed add-ons (read-only: add-ons are installed on the server with sudo ispgrid addon, never from the panel), each with its settings section
     * when it has one and this staff member may change it.
     *
     * @return list<array{key: string, name: string, package: string, version: string, description: string, status: string, color: string, settings: ?string}>
     */
    #[Computed]
    public function addons(): array
    {
        $manifest = app(AddonManifest::class);
        $sections = app(AddonSettings::class)->sections();
        $user = auth()->user();

        return array_map(function (Addon $addon) use ($manifest, $sections, $user): array {
            $section = $sections[$addon->package] ?? null;

            return [
                'key' => str_replace('/', '-', $addon->package),
                'name' => $addon->name,
                'package' => $addon->package,
                'version' => $addon->version,
                'description' => $addon->description,
                'color' => $addon->compatible ? 'green' : 'amber',
                'status' => $addon->compatible
                    ? __('Active')
                    : __('Not loaded: built for core :supports, this is :core', ['supports' => implode(', ', $addon->supports), 'core' => (string) $manifest->coreSeries()]),
                'settings' => $addon->compatible && $section !== null && $user->can($section['ability']) ? $section['component'] : null,
            ];
        }, $manifest->all());
    }
};
