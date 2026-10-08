<?php

use App\Support\Heroicons;

it('returns the outline path data of a known icon', function () {
    expect(Heroicons::paths('cog-6-tooth'))->toHaveCount(2)
        ->and(Heroicons::paths('home')[0])->toStartWith('m2.25 12');
});

it('refuses an icon name it does not know', function () {
    Heroicons::paths('rocket');
})->throws(InvalidArgumentException::class, 'Unknown icon [rocket].');
