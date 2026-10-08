<?php

use Illuminate\Support\Facades\DB;

it('stores every table in InnoDB so a failed save rolls back everything', function () {
    $nonInnoDb = DB::table('information_schema.TABLES')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_TYPE', 'BASE TABLE')
        ->where('ENGINE', '!=', 'InnoDB')
        ->pluck('TABLE_NAME');

    expect($nonInnoDb)->toBeEmpty();
});
