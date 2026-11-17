{{-- The totals of an invoice being written. $rows: label, value and total flag per row. --}}
@if ($rows !== [])
    <dl class="grid grid-cols-1 gap-1 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
        @foreach ($rows as $row)
            <div @class(['flex justify-between gap-4', 'mt-1 border-t border-zinc-200 pt-2 font-semibold dark:border-zinc-700' => $row['total']])>
                <dt @class(['text-zinc-600 dark:text-zinc-400' => ! $row['total']])>{{ $row['label'] }}</dt>
                <dd class="tabular-nums">{{ $row['value'] }}</dd>
            </div>
        @endforeach
    </dl>
@endif
