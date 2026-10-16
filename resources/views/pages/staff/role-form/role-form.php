<?php

use App\Concerns\AuthorizesEveryRequest;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Role')] class extends Component
{
    use AuthorizesEveryRequest;

    #[Locked]
    public ?int $roleId = null;

    public string $name = '';

    /**
     * @var list<string>
     */
    public array $permissions = [];

    /**
     * Admin is not editable: it always has every permission.
     */
    public function mount(?int $role = null): void
    {
        $this->authorizeAccess();

        if ($role === null) {
            return;
        }

        $model = Role::query()->findOrFail($role);
        abort_if($model->isAdmin(), 403);

        $this->roleId = $model->id;
        $this->name = $model->name;
        $this->permissions = $model->permissions->pluck('name')->all();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('manage-staff');
    }

    /**
     * Ticking an action ticks its view box too, so the role can reach the page where it acts.
     */
    public function updatedPermissions(): void
    {
        $this->permissions = array_values(array_unique([...$this->permissions, ...app(Permissions::class)->withImplied($this->permissions)]));
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    #[Computed]
    public function areas(): array
    {
        return app(Permissions::class)->byArea();
    }

    public function save(): void
    {
        $this->authorize('manage-staff');
        $this->name = trim($this->name);

        $this->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique(Role::class, 'name')->ignore($this->roleId)],
            'permissions' => ['array', Rule::in(app(Permissions::class)->names())],
        ], [
            'name.unique' => __('Another role has this name.'),
            'permissions.in' => __('Tick only the boxes on this page.'),
        ], [
            'name' => __('Name'),
            'permissions' => __('Permissions'),
        ]);

        $permissions = app(Permissions::class)->withImplied($this->permissions);

        DB::transaction(function () use ($permissions): void {
            $role = $this->roleId === null ? new Role(['guard_name' => 'web']) : Role::query()->lockForUpdate()->findOrFail($this->roleId);
            abort_if($role->isAdmin(), 403);

            $old = $role->exists
                ? ['name' => $role->name, 'permissions' => Role::permissionLabels($role->permissions->pluck('name')->all())]
                : [];

            $role->name = $this->name;
            $role->save();
            $role->syncPermissions($permissions);

            $new = ['name' => $role->name, 'permissions' => Role::permissionLabels($permissions)];
            $changed = array_keys(array_diff_assoc($new, $old));

            if ($changed !== []) {
                activity()
                    ->performedOn($role)
                    ->event($old === [] ? 'created' : 'updated')
                    ->withChanges(array_filter([
                        'attributes' => array_intersect_key($new, array_flip($changed)),
                        'old' => array_intersect_key($old, array_flip($changed)),
                    ]))
                    ->log($old === [] ? 'created' : 'updated');
            }
        });

        session()->flash('toast', __('Role saved.'));
        $this->redirectRoute('roles.index', navigate: true);
    }
};
