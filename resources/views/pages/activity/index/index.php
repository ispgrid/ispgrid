<?php

use App\Concerns\AuthorizesEveryRequest;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\ActivityFormatter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

new #[Title('Activity log')] class extends Component
{
    use AuthorizesEveryRequest, WithPagination;

    #[Url]
    public string $staff = '';

    #[Url]
    public string $type = '';

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('view-activity-log');
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function activities(): LengthAwarePaginator
    {
        return Activity::query()
            ->with([
                'causer' => fn (MorphTo $morphTo) => $morphTo->constrain([
                    User::class => fn ($query) => $query->withTrashed(),
                ]),
                'subject',
            ])
            ->when($this->staff !== '', fn ($query) => $query
                ->where('causer_type', (new User)->getMorphClass())
                ->where('causer_id', $this->staff))
            ->when($this->type !== '', fn ($query) => $query->where('subject_type', $this->type))
            ->latest('id')
            ->paginate(25);
    }

    /**
     * @return list<array{id: int, when: string, staff: string, event: string, type: string, name: string, changes: list<array{field: string, old: ?string, new: ?string}>}>
     */
    #[Computed]
    public function rows(): array
    {
        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';

        return $this->activities->getCollection()
            ->map(fn (Activity $activity): array => [
                'id' => $activity->id,
                'when' => $activity->created_at->format($format),
                'staff' => ActivityFormatter::causerName($activity),
                'event' => ActivityFormatter::eventLabel($activity),
                'type' => ActivityFormatter::subjectType($activity->subject_type),
                'name' => ActivityFormatter::subjectName($activity),
                'changes' => ActivityFormatter::changes($activity),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function staffOptions(): array
    {
        return User::withTrashed()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function typeOptions(): array
    {
        return collect(ActivityFormatter::SUBJECT_TYPES)
            ->mapWithKeys(fn (string $label, string $class): array => [(new $class)->getMorphClass() => __($label)])
            ->all();
    }
};
