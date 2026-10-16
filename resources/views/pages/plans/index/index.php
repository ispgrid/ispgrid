<?php

use App\Actions\Radius\SyncPlanToRadius;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Plan;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Plans')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('viewAny', Plan::class);
    }

    /**
     * Live plans first, then deleted ones, with how many live customers use each.
     *
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return Plan::withTrashed()
            ->with('pool:id,name')
            ->withCount('accounts')
            ->orderByRaw('deleted_at is not null')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<array{model: Plan, id: int, name: string, slug: string, speed: string, validity: string, price: string, pool: ?string, customers: int, trashed: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        $money = Money::fromSettings();

        return $this->plans->map(fn (Plan $plan): array => [
            'model' => $plan,
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'speed' => $plan->upload.'/'.$plan->download,
            'validity' => $plan->validityLabel(),
            'price' => $money->format((string) $plan->price),
            'pool' => $plan->pool?->name,
            'customers' => $plan->accounts_count,
            'trashed' => $plan->trashed(),
        ])->all();
    }

    /**
     * Soft delete, refused while live customers use the plan. Its radgroupreply rows stay: only an account
     * loses its rows on delete (spec section 2), and no live customer points at the plan any more.
     */
    public function delete(int $planId): void
    {
        $plan = Plan::query()->withCount('accounts')->findOrFail($planId);
        $this->authorize('delete', $plan);

        if ($plan->accounts_count > 0) {
            $this->toast(trans_choice(
                ':count customer uses this plan. Move them to another plan first.|:count customers use this plan. Move them to another plan first.',
                $plan->accounts_count,
            ), 'danger');

            return;
        }

        $plan->delete();

        $this->toast(__('Plan deleted.'));
    }

    /**
     * Restores the plan and rewrites its rows, in case its pool or lists changed while it was deleted.
     */
    public function restore(int $planId): void
    {
        $plan = Plan::onlyTrashed()->findOrFail($planId);
        $this->authorize('restore', $plan);

        // Fetched again inside the transaction, so a deadlock retry restores the row instead of a model that
        // already looks restored.
        DB::transaction(function () use ($planId): void {
            $plan = Plan::onlyTrashed()->lockForUpdate()->findOrFail($planId);
            $plan->restore();
            app(SyncPlanToRadius::class)->handle($plan);
        }, attempts: 3);

        $this->toast(__('Plan restored.'));
    }
};
