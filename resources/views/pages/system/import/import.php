<?php

use App\Actions\Import\ImportCustomers;
use App\Actions\Import\Importer;
use App\Actions\Import\ImportPlans;
use App\Actions\Import\ImportTime;
use App\Actions\Import\RowError;
use App\Exceptions\ImportFailed;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\Plan;
use App\Support\CsvFile;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Import')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts, WithFileUploads;

    /**
     * @var ?TemporaryUploadedFile
     */
    public $plansFile = null;

    /**
     * @var ?TemporaryUploadedFile
     */
    public $customersFile = null;

    /**
     * What the last check or import found, per file.
     *
     * @var array<string, list<array{row: ?int, column: ?string, message: string}>>
     */
    #[Locked]
    public array $problems = ['plans' => [], 'customers' => []];

    /**
     * The row count of a file whose last check found nothing; null until then.
     *
     * @var array<string, ?int>
     */
    #[Locked]
    public array $ready = ['plans' => null, 'customers' => null];

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /**
     * Who may use this page: checked when it opens and on every later request (AuthorizesEveryRequest).
     */
    protected function authorizeAccess(): void
    {
        $this->authorize('import-data');
    }

    public function updatedPlansFile(): void
    {
        $this->forget('plans');
    }

    public function updatedCustomersFile(): void
    {
        $this->forget('customers');
    }

    /**
     * Reads the file and lists every problem. Nothing is written.
     */
    public function check(string $kind): void
    {
        $this->authorize('import-data');
        $importer = $this->importer($kind);
        $this->forget($kind);
        ImportTime::extend();

        $field = $kind.'File';
        $this->validate([$field => ['required', 'file', 'extensions:csv,txt', 'max:2048']], [
            $field.'.required' => __('Choose a file first.'),
            $field.'.extensions' => __('Choose a .csv file.'),
            $field.'.max' => __('The file is larger than 2 MB. Split it into smaller files.'),
        ]);

        $csv = $this->read($kind, $importer);
        // A row CsvFile could not read hides nothing else: the readable rows are checked too, all in row order.
        $problems = [...$csv->errors, ...$importer->check($csv->rows)];
        usort($problems, fn (RowError $a, RowError $b): int => ($a->row ?? 0) <=> ($b->row ?? 0));

        $this->problems[$kind] = array_map(fn (RowError $error): array => $error->toArray(), $problems);
        $this->ready[$kind] = $problems === [] ? count($csv->rows) : null;
    }

    /**
     * Imports the checked file in one transaction: every row or none.
     */
    public function import(string $kind): void
    {
        $this->authorize('import-data');
        $importer = $this->importer($kind);
        ImportTime::extend();

        if ($this->ready[$kind] === null || $this->{$kind.'File'} === null) {
            $this->toast(__('Check the file first.'), 'danger');

            return;
        }

        try {
            $csv = $this->read($kind, $importer);

            if ($csv->errors !== []) {
                throw new ImportFailed($csv->errors);
            }

            $count = $importer->import($csv->rows);
        } catch (ImportFailed $failed) {
            $this->problems[$kind] = array_map(fn (RowError $error): array => $error->toArray(), $failed->errors);
            $this->ready[$kind] = null;

            return;
        }

        $this->reset($kind.'File');
        $this->forget($kind);
        $this->toast($kind === 'plans'
            ? trans_choice(':count plan imported.|:count plans imported.', $count)
            : trans_choice(':count customer imported.|:count customers imported.', $count));
    }

    /**
     * The header row and one example row.
     */
    public function downloadTemplate(string $kind): StreamedResponse
    {
        $this->authorize('import-data');
        $example = $this->importer($kind)->example();

        return response()->streamDownload(function () use ($example): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, array_keys($example), escape: '');
            fputcsv($output, array_values($example), escape: '');
            fclose($output);
        }, "{$kind}-template.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * What each card shows, so the view only loops.
     *
     * @return list<array{kind: string, title: string, description: string, file: string, ready: ?int, readyText: string, importText: string, problemsText: string, problems: list<array{row: string, column: string, message: string}>}>
     */
    #[Computed]
    public function cards(): array
    {
        $cards = [
            'plans' => [__('Plans'), __('One plan per row. Speeds use k or M, e.g. 25M. The pool is a pool name and may be empty.')],
            'customers' => [__('Customers'), __('Import plans first: customers refer to plans by slug. Expiry is YYYY-MM-DD HH:MM; customers without a plan start Inactive. In Excel, format the username, password, mobile and expiry columns as Text before typing.')],
        ];
        // Only what the staff member may also add through the forms.
        $cards = array_filter($cards, fn (string $kind): bool => auth()->user()->can('create', $this->model($kind)), ARRAY_FILTER_USE_KEY);

        return array_map(function (string $kind, array $text): array {
            $ready = $this->ready[$kind];
            $problems = $this->problems[$kind];

            return [
                'kind' => $kind,
                'title' => $text[0],
                'description' => $text[1],
                'file' => $kind.'File',
                'ready' => $ready,
                'readyText' => $ready === null ? '' : trans_choice(':count row is ready to import.|:count rows are ready to import.', $ready),
                'importText' => $ready === null ? '' : trans_choice('Import :count row|Import :count rows', $ready),
                'problemsText' => trans_choice('Nothing was saved. Fix :count problem and check the file again.|Nothing was saved. Fix :count problems and check the file again.', count($problems)),
                'problems' => array_map(fn (array $problem): array => [
                    'row' => (string) ($problem['row'] ?? '-'),
                    'column' => $problem['column'] ?? '-',
                    'message' => $problem['message'],
                ], $problems),
            ];
        }, array_keys($cards), array_values($cards));
    }

    /**
     * The importer for the kind, once the staff member may also create what it adds, as the forms require.
     */
    private function importer(string $kind): Importer
    {
        $this->authorize('create', $this->model($kind));

        return app($kind === 'plans' ? ImportPlans::class : ImportCustomers::class);
    }

    /**
     * The model a kind of file adds rows of.
     *
     * @return class-string<Plan|Account>
     */
    private function model(string $kind): string
    {
        return match ($kind) {
            'plans' => Plan::class,
            'customers' => Account::class,
            default => abort(404),
        };
    }

    private function read(string $kind, Importer $importer): CsvFile
    {
        return CsvFile::read($this->{$kind.'File'}->getRealPath(), $importer->columns(), $importer->requiredColumns());
    }

    private function forget(string $kind): void
    {
        $this->problems[$kind] = [];
        $this->ready[$kind] = null;
    }
};
