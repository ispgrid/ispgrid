<?php

use App\Actions\Radius\ReplaceRadiusValue;
use Illuminate\Support\Facades\DB;

it('rewrites one attribute value only for the given users', function () {
    DB::table('radcheck')->insert([
        ['username' => 'alice', 'attribute' => 'Isp-Nas', 'op' => ':=', 'value' => '10.0.0.1'],
        ['username' => 'alice', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => '10.0.0.1'],
        ['username' => 'bob', 'attribute' => 'Isp-Nas', 'op' => ':=', 'value' => '10.0.0.1'],
        ['username' => 'carol', 'attribute' => 'Isp-Nas', 'op' => ':=', 'value' => '10.0.0.2'],
    ]);

    $changed = app(ReplaceRadiusValue::class)->handle('radcheck', ['alice', 'carol'], 'Isp-Nas', '10.0.0.1', '10.0.0.9');

    expect($changed)->toBe(1)
        ->and(DB::table('radcheck')->orderBy('id')->pluck('value')->all())
        ->toBe(['10.0.0.9', '10.0.0.1', '10.0.0.1', '10.0.0.2']);
});

it('rewrites plan rows by group name', function () {
    DB::table('radgroupreply')->insert([
        ['groupname' => 'home_50', 'attribute' => 'Framed-Pool', 'op' => '=', 'value' => 'pppoe'],
        ['groupname' => 'biz_100', 'attribute' => 'Framed-Pool', 'op' => '=', 'value' => 'pppoe'],
    ]);

    app(ReplaceRadiusValue::class)->handle('radgroupreply', collect(['home_50']), 'Framed-Pool', 'pppoe', 'pppoe_main');

    expect(DB::table('radgroupreply')->orderBy('id')->pluck('value')->all())->toBe(['pppoe_main', 'pppoe']);
});

it('changes nothing when there are no owners', function () {
    expect(app(ReplaceRadiusValue::class)->handle('radreply', [], 'Framed-Pool', 'a', 'b'))->toBe(0);
});
