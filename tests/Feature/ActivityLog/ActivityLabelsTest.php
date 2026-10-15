<?php

use App\Support\ActivityFormatter;
use Spatie\Activitylog\Models\Activity;

it('shows an add-on event with the label the add-on gave it', function () {
    ActivityFormatter::addEventLabel('records-searched', fn (): string => 'Records searched');

    expect(ActivityFormatter::eventLabel(new Activity(['event' => 'records-searched'])))->toBe('Records searched')
        ->and(ActivityFormatter::eventLabel(new Activity(['event' => 'unknown-event'])))->toBe('unknown-event');
});
