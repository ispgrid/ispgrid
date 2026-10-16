<?php

use App\Actions\Fortify\CreateNewUser;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Staff')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * The role id, for "Add staff" and for "Change role".
     */
    public string $role = '';

    /**
     * Whose role the Change role dialog changes.
     */
    #[Locked]
    public ?int $roleStaffId = null;

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('viewAny', User::class);
    }

    /**
     * Active staff first, then disabled staff, each by name.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function staff(): Collection
    {
        return User::withTrashed()
            ->with('roles')
            ->orderByRaw('deleted_at is not null')
            ->orderBy('name')
            ->get();
    }

    /**
     * Role id => name, Admin first.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function roles(): array
    {
        return Role::query()
            ->orderByRaw('name <> ?', [Role::ADMIN])
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Role $role): array => [$role->id => $role->label()])
            ->all();
    }

    public function add(CreateNewUser $createNewUser): void
    {
        $this->authorize('create', User::class);
        $this->validateRole();

        $staff = DB::transaction(function () use ($createNewUser): User {
            $staff = $createNewUser->create($this->only(['name', 'email', 'password', 'password_confirmation']));
            $staff->assignRole((int) $this->role);

            return $staff;
        });

        $this->reset('name', 'email', 'password', 'password_confirmation', 'role');
        $this->dispatch('modal-close', name: 'add-staff');
        $this->toast(__(':name can now log in.', ['name' => $staff->name]));
    }

    /**
     * Opens the Change role dialog. Your own role is not offered: with disabling yourself also refused, the Admin
     * acting always stays an active Admin.
     */
    public function editRole(int $staffId): void
    {
        $staff = User::withTrashed()->findOrFail($staffId);
        $this->authorize('changeRole', $staff);

        $this->resetValidation();
        $this->roleStaffId = $staff->id;
        $this->role = (string) ($staff->staffRole()?->id ?? '');

        $this->dispatch('modal-open', name: 'change-role');
    }

    public function saveRole(): void
    {
        $staff = User::withTrashed()->findOrFail($this->roleStaffId);
        $this->authorize('changeRole', $staff);
        $this->validateRole();

        $old = $staff->staffRole();
        $new = Role::query()->findOrFail((int) $this->role);

        if ($old?->isNot($new)) {
            $staff->syncRoles([$new]);

            activity()
                ->performedOn($staff)
                ->event('role-changed')
                ->withChanges(['attributes' => ['role' => $new->name], 'old' => ['role' => $old?->name]])
                ->log('role-changed');
        }

        $this->reset('role', 'roleStaffId');
        $this->dispatch('modal-close', name: 'change-role');
        $this->toast(__(':name is now :role.', ['name' => $staff->name, 'role' => $new->label()]));
    }

    public function disable(int $staffId): void
    {
        $staff = User::query()->findOrFail($staffId);
        $this->authorize('delete', $staff);

        $staff->delete();

        $this->toast(__(':name is disabled.', ['name' => $staff->name]));
    }

    public function restore(int $staffId): void
    {
        $staff = User::onlyTrashed()->findOrFail($staffId);
        $this->authorize('restore', $staff);

        $staff->restore();

        $this->toast(__(':name can log in again.', ['name' => $staff->name]));
    }

    private function validateRole(): void
    {
        $this->validate(
            ['role' => ['required', 'integer', Rule::exists(Role::class, 'id')]],
            ['role.required' => __('Choose a role.'), 'role.exists' => __('Choose a role.')],
        );
    }
};
