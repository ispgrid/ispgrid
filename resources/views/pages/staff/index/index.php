<?php

use App\Actions\Fortify\CreateNewUser;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Staff')] class extends Component
{
    use ShowsToasts;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
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
            ->orderByRaw('deleted_at is not null')
            ->orderBy('name')
            ->get();
    }

    public function add(CreateNewUser $createNewUser): void
    {
        $this->authorize('create', User::class);

        $staff = $createNewUser->create($this->only(['name', 'email', 'password', 'password_confirmation']));

        $this->reset('name', 'email', 'password', 'password_confirmation');
        $this->dispatch('modal-close', name: 'add-staff');
        $this->toast(__(':name can now log in.', ['name' => $staff->name]));
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
};
