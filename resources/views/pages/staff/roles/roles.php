<?php

use App\Enums\Permission;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Role;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Roles')] class extends Component
{
    use ShowsToasts;

    public function mount(): void
    {
        $this->authorize('manage-staff');
    }

    /**
     * Admin first, then the others by name, each with how many staff (disabled ones too) have it.
     *
     * @return list<array{id: int, name: string, admin: bool, staff: int, permissions: int}>
     */
    #[Computed]
    public function rows(): array
    {
        return Role::query()
            ->withCount(['permissions', 'users' => fn ($query) => $query->withTrashed()])
            ->orderByRaw('name <> ?', [Role::ADMIN])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->label(),
                'admin' => $role->isAdmin(),
                'staff' => $role->users_count,
                'permissions' => $role->permissions_count,
            ])
            ->all();
    }

    #[Computed]
    public function permissionCount(): int
    {
        return count(Permission::cases());
    }

    /**
     * Refused while anyone has the role, so no staff member is left without one.
     */
    public function delete(int $roleId): void
    {
        $this->authorize('manage-staff');

        $role = Role::query()->findOrFail($roleId);
        abort_if($role->isAdmin(), 403);

        $holders = User::withTrashed()->role($role)->count();

        if ($holders > 0) {
            $this->toast(trans_choice(
                ':count staff member has this role. Give them another role first.|:count staff members have this role. Give them another role first.',
                $holders,
            ), 'danger');

            return;
        }

        $old = ['name' => $role->name, 'permissions' => Role::permissionLabels($role->permissions->pluck('name')->all())];
        $role->delete();

        activity()->performedOn($role)->event('deleted')->withChanges(['old' => $old])->log('deleted');

        $this->toast(__('Role deleted.'));
    }
};
