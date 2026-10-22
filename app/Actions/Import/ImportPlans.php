<?php

namespace App\Actions\Import;

use App\Actions\Radius\SyncPlanToRadius;
use App\Exceptions\ImportFailed;
use App\Models\Plan;
use App\Models\Pool;
use App\Support\CsvFile;
use App\Support\PlanRules;
use App\Support\RateLimit;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Plans from the plans template: the plan form's rules, written through SyncPlanToRadius like the form saves.
 * Only the template's columns; burst, limits, slots, lists and custom attributes stay in the form.
 */
final class ImportPlans implements Importer
{
    /**
     * Plan form field => CSV column, where they differ.
     *
     * @var array<string, string>
     */
    private const COLUMN_OF = ['validity' => 'validity_days', 'upload_speed' => 'upload', 'download_speed' => 'download', 'pool_id' => 'pool'];

    public function __construct(private SyncPlanToRadius $sync) {}

    public function columns(): array
    {
        return array_keys($this->example());
    }

    public function requiredColumns(): array
    {
        return ['slug', 'name', 'validity_days', 'price', 'upload', 'download'];
    }

    public function example(): array
    {
        return [
            'slug' => 'home_25', 'name' => 'Home 25', 'validity_days' => '30', 'price' => '599',
            'upload' => '25M', 'download' => '25M', 'pool' => '', 'description' => 'Home plan, 25 Mbps',
        ];
    }

    public function check(array $rows): array
    {
        return array_merge(...array_column($this->prepare($rows), 'errors'));
    }

    public function import(array $rows): int
    {
        try {
            return DB::transaction(function () use ($rows): int {
                $prepared = $this->prepare($rows);
                $errors = array_merge(...array_column($prepared, 'errors'));

                if ($errors !== []) {
                    throw new ImportFailed($errors);
                }

                foreach ($prepared as $row) {
                    // One "Imported" entry below instead of the model's own "Created" one.
                    $plan = activity()->withoutLogging(fn (): Plan => Plan::query()->create($row['columns']));
                    $this->sync->handle($plan);
                    $this->logImported($plan);
                }

                return count($prepared);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw new ImportFailed([new RowError(null, null, __('A record in this file was taken meanwhile by someone else. Check the file again.'))]);
        }
    }

    /**
     * Each row's problems and, when it has none, the plan's columns.
     *
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @return list<array{errors: list<RowError>, columns: array<string, mixed>}>
     */
    private function prepare(array $rows): array
    {
        $pools = Pool::query()->get(['id', 'name'])->keyBy(fn (Pool $pool): string => strtolower($pool->name));
        $seen = [];
        $prepared = [];

        foreach ($rows as ['line' => $line, 'values' => $values]) {
            $errors = CsvFile::textErrors($values, ['name', 'description']);

            $pool = $values['pool'] === '' ? null : $pools->get(strtolower($values['pool']));

            if ($values['pool'] !== '' && $pool === null) {
                $errors['pool'] = __('No pool named :name.', ['name' => $values['pool']]);
            }

            $data = [
                'slug' => $values['slug'],
                'name' => $values['name'],
                'validity' => $values['validity_days'],
                'price' => $values['price'],
                'description' => $values['description'] === '' ? null : $values['description'],
                'upload_speed' => $values['upload'],
                'download_speed' => $values['download'],
                'pool_id' => $pool?->id,
            ];

            $validator = Validator::make($data, PlanRules::rules(true), PlanRules::messages(), self::attributeNames());

            foreach ($validator->errors()->messages() as $field => $messages) {
                $errors[self::COLUMN_OF[$field] ?? $field] ??= $messages[0];
            }

            if (! isset($errors['upload']) && ! isset($errors['download'])) {
                foreach ((new RateLimit($values['upload'], $values['download']))->errors() as $field => $message) {
                    $errors[$field] ??= $message;
                }
            }

            $slug = strtolower($values['slug']);

            if ($slug !== '' && isset($seen[$slug])) {
                $errors['slug'] ??= __('Row :row already uses this slug.', ['row' => $seen[$slug]]);
            }

            $seen[$slug] ??= $line;

            $prepared[] = [
                'errors' => array_map(
                    fn (string $column, string $message): RowError => new RowError($line, $column, $message),
                    array_keys($errors),
                    array_values($errors),
                ),
                'columns' => [
                    'slug' => $values['slug'],
                    'name' => $values['name'],
                    'validity' => (int) $values['validity_days'],
                    'price' => $values['price'],
                    'description' => $data['description'],
                    'upload' => $values['upload'],
                    'download' => $values['download'],
                    'pool_id' => $pool?->id,
                ],
            ];
        }

        return $prepared;
    }

    /**
     * The CSV column each message names, e.g. "The validity days field ..." rather than "The validity field ...".
     *
     * @return array<string, string>
     */
    private static function attributeNames(): array
    {
        return array_map(fn (string $column): string => str_replace('_', ' ', $column), self::COLUMN_OF);
    }

    /**
     * One "Imported" entry with the plan's logged columns, like the form's "Created" entry.
     */
    private function logImported(Plan $plan): void
    {
        $attributes = [];

        foreach ($plan->getActivitylogOptions()->logAttributes as $field) {
            $value = $plan->getAttribute($field);
            $attributes[$field] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
                default => $value,
            };
        }

        activity()->performedOn($plan)->event('imported')->withChanges(['attributes' => $attributes])->log('imported');
    }
}
