<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Billing\PaymentInput;
use App\Enums\ItemSoldAs;
use App\Enums\ServiceKind;
use App\Enums\ServiceType;
use App\Exceptions\CannotAddService;
use App\Models\Account;
use App\Models\CustomerService;
use App\Models\Ip;
use App\Models\Item;
use App\Support\CustomerRules;
use App\Support\IpAddress;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Add service: the customer takes a Service item for some months from a start day (today or earlier, at the current
 * time of day), paid until start + N calendar months (31 Jan + 1 month = end of February). A static IP service takes
 * the chosen address as the customer's fixed IP (freeing the one they had, if another) and FreeRADIUS sends it from
 * the next login. Issues the service's own invoice, with the payment taken now if any.
 */
class AddCustomerService
{
    public function __construct(private SyncAccountToRadius $sync, private RecordServiceRenewal $record) {}

    public function handle(Account $account, Item $item, int $months, CarbonInterface $start, string $amount, ?string $ipAddress, ?PaymentInput $payment): CustomerService
    {
        if ($item->sold_as !== ItemSoldAs::Service || ! $item->active) {
            throw new CannotAddService('not-service');
        }

        $static = $item->service_kind === ServiceKind::StaticIp;

        return DB::transaction(function () use ($account, $item, $months, $start, $amount, $ipAddress, $payment, $static): CustomerService {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            $ip = $static ? $this->staticIp($account, (string) $ipAddress) : null;
            $from = $start->copy()->setTimeFrom(now());
            $from = $from->greaterThan(now()) ? now() : $from;

            if (! $from->copy()->addMonthsNoOverflow($months)->greaterThan(now())) {
                throw new CannotAddService('ends-in-past');
            }

            $service = CustomerService::query()->create([
                'account_id' => $account->id,
                'item_id' => $item->id,
                'ip_id' => $ip?->id,
                'paid_until' => $from->copy()->addMonthsNoOverflow($months),
            ]);

            if ($ip !== null) {
                $previousIpId = $account->ip_id;
                $account->disableLogging();
                $account->update(['ip_id' => $ip->id]);
                $this->sync->handle($account, $previousIpId);
            }

            $renewal = $this->record->handle($service, $account, $months, $amount, null, $from, $payment);

            activity()
                ->performedOn($account)
                ->event('service-added')
                ->withChanges(['attributes' => [
                    'service' => $item->name, 'months' => $months, 'paid_until' => $service->paid_until->format(RechargeAccount::DATE_FORMAT),
                    'amount' => (string) $renewal->amount, ...($ip === null ? [] : ['ip' => $ip->address]),
                ]])
                ->log('service-added');

            return $service;
        }, attempts: 3);
    }

    /**
     * The address for a new static IP service, locked so two staff cannot give it out at once: a PPPoE customer
     * without another static IP service, and an address in the inventory that nobody else has or holds.
     */
    private function staticIp(Account $account, string $address): Ip
    {
        if ($account->service_type !== ServiceType::Ppp) {
            throw new CannotAddService('not-pppoe');
        }

        if ($account->staticIpService()->exists()) {
            throw new CannotAddService('has-static-ip');
        }

        $address = trim($address);

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new CannotAddService('ip-invalid', __('Enter an IPv4 address.'));
        }

        $ip = Ip::query()->where('address', IpAddress::normalize($address))->lockForUpdate()->first();
        $error = CustomerRules::ipError($address, $account->id);

        if ($ip === null || $error !== null) {
            throw new CannotAddService('ip-invalid', $error ?? __('This address is not in the IP inventory, or it was taken out. Add its range under Network, IP ranges.'));
        }

        return $ip;
    }
}
