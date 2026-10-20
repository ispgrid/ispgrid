<?php

use App\Models\Account;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\ActivityFormatter;
use App\Support\ActivityTimeline;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-20 13:27:00'));
    AppSetting::put('currency_symbol', '₹');
    $this->account = Account::factory()->create();
    $this->staff = User::factory()->create(['name' => 'Linkio Care', 'email' => 'care@example.com']);
});

/**
 * One entry about the customer, by the staff member unless $by is null, read back as the timeline shows it.
 */
function timelineEntry(string $event, array $old, array $new, ?User $by): array
{
    $logger = activity()->performedOn(test()->account)->event($event)->withChanges(['old' => $old, 'attributes' => $new]);
    $by === null ? $logger->causedByAnonymous() : $logger->causedBy($by);
    $logger->log($event);

    return ActivityTimeline::entry(Activity::query()->latest('id')->first(), 'd-M-Y');
}

it('writes one sentence and a tone for each kind of entry', function (string $event, array $old, array $new, string $sentence, string $tone) {
    $entry = timelineEntry($event, $old, $new, $this->staff);

    expect($entry['sentence'])->toBe($sentence)
        ->and($entry['tone'])->toBe($tone)
        ->and($entry['by'])->toBe('Linkio Care (care@example.com)')
        ->and($entry['day'])->toBe('20-Oct-2026')
        ->and($entry['time'])->toBe('13:27');
})->with([
    'created' => ['created', [], ['username' => 'alice'], 'Customer created.', 'green'],
    'recharged' => ['recharged', ['plan' => null, 'expiry' => '2026-05-01 10:00'], ['plan' => '50Mbps', 'expiry' => '2026-11-18 23:59', 'amount' => '825', 'note' => null],
        'Recharged: 50Mbps, 20-Oct-2026 13:27 to 18-Nov-2026 23:59, ₹825.00.', 'blue'],
    'recharged early' => ['recharged', ['plan' => '50Mbps', 'expiry' => '2026-10-31 00:00'], ['plan' => '50Mbps', 'expiry' => '2026-11-30 00:00', 'amount' => '825', 'note' => null],
        'Recharged: 50Mbps, 31-Oct-2026 00:00 to 30-Nov-2026 00:00, ₹825.00.', 'blue'],
    'plan changed' => ['plan-changed', ['plan' => '50Mbps', 'expiry' => '2026-10-31 00:00'], ['plan' => '100Mbps', 'expiry' => '2027-04-20 23:59', 'amount' => '3304', 'note' => null],
        'Plan changed from 50Mbps to 100Mbps, now to 20-Apr-2027 23:59, ₹3,304.00.', 'blue'],
    'advance booked' => ['advance-booked', [], ['plan' => '100Mbps', 'starts' => '2027-02-01 00:00', 'expiry' => '2027-07-31 23:59', 'amount' => '3304', 'note' => null],
        'Advance renewal booked: 100Mbps from 01-Feb-2027 00:00, ₹3,304.00.', 'blue'],
    'advance started' => ['advance-started', ['plan' => '50Mbps', 'expiry' => '2027-02-01 00:00'], ['plan' => '100Mbps', 'expiry' => '2027-07-31 23:59', 'amount' => '3304', 'note' => null],
        'Advance renewal started: 100Mbps, 01-Feb-2027 00:00 to 31-Jul-2027 23:59, ₹3,304.00.', 'blue'],
    'advance cancelled' => ['advance-cancelled', ['plan' => '100Mbps', 'starts' => '2027-02-01 00:00'], [], 'Advance renewal cancelled.', 'blue'],
    'advance moved' => ['advance-moved', ['starts' => '2027-02-01 00:00'], ['starts' => '2027-03-01 00:00'], 'Advance renewal moved to start 01-Mar-2027 00:00.', 'blue'],
    'auto renewed' => ['auto-renewed', ['plan' => '50Mbps', 'expiry' => '2026-11-01 00:00'], ['plan' => '50Mbps', 'expiry' => '2026-12-01 00:00', 'amount' => '825', 'note' => null],
        'Auto renewed: 50Mbps, 01-Nov-2026 00:00 to 01-Dec-2026 00:00, ₹825.00.', 'blue'],
    'auto renew skipped' => ['auto-renew-skipped', [], ['reason' => 'The customer is Suspended', 'expiry' => '2026-11-01 00:00'], 'Auto renew skipped: The customer is Suspended.', 'red'],
    'expired' => ['expired', [], ['plan' => '50Mbps', 'expiry' => '2026-07-31 23:59'], 'Plan expired: 50Mbps ended 31-Jul-2026 23:59.', 'red'],
    'expiry changed' => ['expiry-changed', ['expiry' => '2026-11-18 23:59'], ['expiry' => '2026-07-31 23:59', 'note' => null], 'Expiry changed from 18-Nov-2026 23:59 to 31-Jul-2026 23:59.', 'amber'],
    'override speed until' => ['own-speed-set', [], ['speed' => '70M/25M', 'until' => '2027-01-31 00:00', 'note' => null], 'Override speed set to 70M/25M until 31-Jan-2027 00:00.', 'amber'],
    'override speed' => ['own-speed-set', [], ['speed' => '70M/25M', 'until' => null, 'note' => null], 'Override speed set to 70M/25M with no end date.', 'amber'],
    'override speed removed' => ['own-speed-removed', ['speed' => '70M/25M'], [], 'Override speed removed.', 'amber'],
    'override speed ended' => ['own-speed-ended', ['speed' => '70M/25M'], [], 'Override speed ended.', 'amber'],
    'terminated' => ['terminated', ['status' => 'Active', 'plan' => '50Mbps'], ['status' => 'Terminated', 'note' => 'Moved'], 'Terminated.', 'red'],
    'status to active' => ['status-changed', ['status' => 'Suspended'], ['status' => 'Active', 'remark' => 'Paid'], 'Status changed from Suspended to Active.', 'green'],
    'status to suspended' => ['status-changed', ['status' => 'Active'], ['status' => 'Suspended', 'remark' => 'Unpaid'], 'Status changed from Active to Suspended.', 'red'],
    'updated' => ['updated', ['remarks' => 'a'], ['remarks' => 'b'], 'Details changed.', 'amber'],
    'disconnected' => ['disconnected', [], [], 'Disconnected.', 'grey'],
]);

it('keeps the comment or remark as the note, and the changed fields only for details changes', function () {
    $status = timelineEntry('status-changed', ['status' => 'Active'], ['status' => 'Suspended', 'remark' => 'Unpaid'], $this->staff);
    $updated = timelineEntry('updated', ['remarks' => 'a'], ['remarks' => 'b'], $this->staff);

    expect($status['note'])->toBe('Unpaid')
        ->and($status['changes'])->toBe([])
        ->and($updated['changes'])->toBe([['field' => 'remarks', 'old' => 'a', 'new' => 'b']]);
});

it('falls back to the event label for an unknown event', function () {
    ActivityFormatter::addEventLabel('document-viewed', fn (): string => 'Document viewed');

    expect(timelineEntry('document-viewed', [], ['file' => 'Aadhaar'], $this->staff))
        ->sentence->toBe('Document viewed.')
        ->tone->toBe('grey')
        ->changes->toBe([['field' => 'file', 'old' => null, 'new' => 'Aadhaar']])
        ->and(timelineEntry('something-new', [], [], $this->staff)['sentence'])->toBe('something-new.');
});

it('shows System for an entry without a causer and the name of a deleted staff member', function () {
    expect(timelineEntry('expired', [], ['plan' => 'Home', 'expiry' => '2026-10-20 00:00'], null)['by'])->toBe('System');

    timelineEntry('updated', [], [], $this->staff);
    $this->staff->delete();
    $activity = Activity::query()->whereMorphedTo('subject', $this->account)->latest('id')
        ->with(['causer' => fn (MorphTo $morphTo) => $morphTo->constrain([User::class => fn ($query) => $query->withTrashed()])])
        ->first();

    expect(ActivityTimeline::entry($activity, 'd-M-Y')['by'])->toBe('Linkio Care (care@example.com)');
});

it('labels the renewal events for the activity log and the Action filter', function () {
    $label = fn (string $event): string => ActivityFormatter::eventLabel(new Activity(['event' => $event]));

    expect($label('plan-changed'))->toBe('Plan changed')
        ->and($label('advance-started'))->toBe('Advance renewal started')
        ->and($label('auto-renew-skipped'))->toBe('Auto renew skipped')
        ->and($label('own-speed-ended'))->toBe('Override speed ended')
        ->and($label('terminated'))->toBe('Terminated');
});
