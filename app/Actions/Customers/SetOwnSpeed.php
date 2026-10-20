<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Models\Account;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Gives the customer their own upload and download speed instead of the plan's, until a date or until removed,
 * or removes it (both speeds null). Setting keeps the other custom speed fields (burst, priority, ...) the
 * customer form set; removing clears them all. The caller disconnects an online customer so the speed applies.
 */
final class SetOwnSpeed
{
    /**
     * The custom speed fields the customer form edits.
     */
    private const FIELDS = ['override_upload', 'override_download', 'override_burst_upload', 'override_burst_download',
        'override_threshold_upload', 'override_threshold_download', 'override_burst_time', 'override_priority',
        'override_min_upload', 'override_min_download'];

    public function __construct(private SyncAccountToRadius $sync) {}

    /**
     * @param  string  $removedEvent  the event a removal logs: own-speed-removed by staff, own-speed-ended when
     *                                customers:renewals removes it at its until time
     *
     * @throws ModelNotFoundException when the customer was deleted meanwhile
     * @throws InvalidArgumentException when MikroTik would not read the speed
     */
    public function handle(int $accountId, ?string $upload, ?string $download, ?CarbonInterface $until, ?string $note, string $removedEvent = 'own-speed-removed'): void
    {
        DB::transaction(function () use ($accountId, $upload, $download, $until, $note, $removedEvent): void {
            $account = Account::query()->lockForUpdate()->findOrFail($accountId);
            $oldSpeed = $account->override_plan ? "{$account->override_upload}/{$account->override_download}" : null;
            $account->disableLogging();

            if ($upload === null && $download === null) {
                $account->update(['override_plan' => false, 'override_until' => null, ...array_fill_keys(self::FIELDS, null)]);
                $this->sync->handle($account);

                activity()
                    ->performedOn($account)
                    ->event($removedEvent)
                    ->withChanges(['old' => ['speed' => $oldSpeed], 'attributes' => array_filter(['note' => $note])])
                    ->log($removedEvent);

                return;
            }

            if (self::errors($account, (string) $upload, (string) $download) !== []) {
                throw new InvalidArgumentException('MikroTik would not read this speed.');
            }

            $account->update(['override_plan' => true, 'override_upload' => $upload, 'override_download' => $download, 'override_until' => $until]);
            $this->sync->handle($account);

            activity()
                ->performedOn($account)
                ->event('own-speed-set')
                ->withChanges([
                    'old' => array_filter(['speed' => $oldSpeed]),
                    'attributes' => ['speed' => "{$upload}/{$download}", 'until' => $until?->format(RechargeAccount::DATE_FORMAT), 'note' => $note],
                ])
                ->log('own-speed-set');
        }, attempts: 3);
    }

    /**
     * What is wrong with this upload and download for the customer, with their other custom speed fields as they
     * are, keyed by field (upload, download, burst_upload, ...). Empty when MikroTik reads it.
     *
     * @return array<string, string>
     */
    public static function errors(Account $account, string $upload, string $download): array
    {
        return SyncAccountToRadius::rateLimit($account->replicate()->fill(['override_upload' => $upload, 'override_download' => $download]))->errors();
    }
}
