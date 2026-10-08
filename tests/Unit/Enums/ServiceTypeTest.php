<?php

use App\Enums\ServiceType;

test('only static leased lines stay out of RADIUS', function (ServiceType $type, bool $usesRadius) {
    expect($type->usesRadius())->toBe($usesRadius);
})->with([
    'PPPoE' => [ServiceType::Ppp, true],
    'Hotspot' => [ServiceType::Hotspot, true],
    'IPoE' => [ServiceType::Ipoe, true],
    'static leased line' => [ServiceType::Static, false],
]);
