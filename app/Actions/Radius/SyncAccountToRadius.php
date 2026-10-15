<?php

namespace App\Actions\Radius;

use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\Ip;
use App\Models\Ipoe;
use App\Support\RadiusText;
use App\Support\RateLimit;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Rebuilds one account's FreeRADIUS rows from the app tables (app-integration.md section 1): radcheck,
 * radusergroup, radreply and, for IPoE, its ipoes row; and keeps ips.is_used. A deleted account and a STATIC
 * leased line get no rows. A Hotspot account whose validity waits for the first login gets
 * Hotspot-First-Login-Validity (minutes) instead of Expiration; FreeRADIUS turns it into an Expiration on that
 * login.
 * Call it inside the save's transaction: FreeRADIUS reads the old rows until the commit and the new ones after.
 * Not final, so a test can make it fail and prove the save rolls back.
 */
class SyncAccountToRadius
{
    /**
     * How FreeRADIUS reads Expiration and Isp-Usage-Since (server local time).
     */
    public const DATE_FORMAT = 'd M Y H:i';

    /**
     * @param  ?int  $previousIpId  the IP the account had before this save; freed when it changed
     * @return bool whether FreeRADIUS now reads different rows (an online customer then needs a disconnect)
     */
    public function handle(Account $account, ?int $previousIpId = null): bool
    {
        $this->refreshClock($account);
        $account->load(['plan', 'nas', 'pool', 'ip', 'addressLists', 'radiusAttributes']);
        $username = $account->username;
        $before = $this->snapshot($account);

        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            DB::table($table)->where('username', $username)->delete();
        }

        Ipoe::query()->where('account_id', $account->id)->delete();

        if ($previousIpId !== null && $previousIpId !== $account->ip_id) {
            Ip::query()->whereKey($previousIpId)->update(['is_used' => false]);
        }

        if ($account->ip_id !== null) {
            Ip::query()->whereKey($account->ip_id)->update(['is_used' => true]);
        }

        if (! $account->trashed() && $account->service_type->usesRadius()) {
            $this->writeRows($account);
        }

        return $this->snapshot($account) !== $before;
    }

    /**
     * FreeRADIUS writes these columns on a Hotspot first login (isp post-auth step 9b) while holding the account
     * row lock. Re-reading them under the same lock means a copy loaded before that login cannot write the
     * validity row back and restart the clock.
     */
    private function refreshClock(Account $account): void
    {
        $columns = ['expiry', 'hotspot_pending_validity', 'hotspot_first_login_at'];
        $fresh = Account::query()->withTrashed()->whereKey($account->id)->lockForUpdate()->first($columns);

        if ($fresh !== null) {
            $account->forceFill($fresh->only($columns))->syncOriginalAttributes($columns);
        }
    }

    private function writeRows(Account $account): void
    {
        $username = $account->username;
        $check = $this->owned($username, $this->checkRows($account));
        $reply = $this->owned($username, $this->replyRows($account));
        $group = $account->plan !== null ? ['username' => $username, 'groupname' => $account->plan->slug, 'priority' => 1] : null;

        RadiusText::check('radcheck', $check);
        RadiusText::check('radreply', $reply);
        RadiusText::check('radusergroup', $group ?? []);

        DB::table('radcheck')->insert($check);
        DB::table('radreply')->insert($reply);

        if ($group !== null) {
            DB::table('radusergroup')->insert($group);
        }

        if ($account->service_type === ServiceType::Ipoe && $account->ip !== null) {
            Ipoe::query()->create(['account_id' => $account->id, 'username' => $username, 'ip_address' => $account->ip->address]);
        }
    }

    /**
     * What FreeRADIUS reads for the account, in a form two snapshots can be compared in.
     *
     * @return array<string, list<string>>
     */
    private function snapshot(Account $account): array
    {
        $rows = fn (string $table): array => DB::table($table)->where('username', $account->username)->orderBy('id')
            ->get(['attribute', 'op', 'value'])
            ->map(fn (object $row): string => "{$row->attribute} {$row->op} {$row->value}")
            ->all();

        return [
            'radcheck' => $rows('radcheck'),
            'radreply' => $rows('radreply'),
            'radusergroup' => DB::table('radusergroup')->where('username', $account->username)->orderBy('groupname')->pluck('groupname')->all(),
            'ipoes' => Ipoe::query()->where('account_id', $account->id)->pluck('ip_address')->all(),
        ];
    }

    public static function rateLimit(Account $account): RateLimit
    {
        return new RateLimit(
            (string) $account->override_upload, (string) $account->override_download,
            $account->override_burst_upload, $account->override_burst_download,
            $account->override_threshold_upload, $account->override_threshold_download,
            $account->override_burst_time, $account->override_priority,
            $account->override_min_upload, $account->override_min_download,
        );
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|int}>
     */
    private function checkRows(Account $account): array
    {
        $isIpoe = $account->service_type === ServiceType::Ipoe;
        $rows = [];

        if ($account->password !== null) {
            $rows[] = ['Cleartext-Password', ':=', $account->password];
        }

        $rows[] = ['Acct-Service-Type', ':=', $account->service_type->value];
        $rows[] = ['Acct-Status-Id', ':=', $account->status_id->value];
        $rows[] = ['Acct-Mac-Type', ':=', $isIpoe ? 1 : $account->mac_type];

        if ($account->hotspot_pending_validity !== null) {
            if ($account->service_type !== ServiceType::Hotspot || $account->expiry !== null) {
                throw new LogicException("{$account->username}: a validity waiting for the first login needs a Hotspot account without an expiry.");
            }

            $rows[] = ['Hotspot-First-Login-Validity', ':=', $account->hotspot_pending_validity];
        } elseif ($account->expiry !== null) {
            $rows[] = ['Expiration', ':=', $account->expiry->format(self::DATE_FORMAT)];
        }

        if ($account->usage_since !== null) {
            $rows[] = ['Isp-Usage-Since', ':=', $account->usage_since->format(self::DATE_FORMAT)];
        }

        if ($account->nas !== null) {
            $rows[] = ['Isp-Nas', ':=', $account->nas->nasname];
        }

        return $rows;
    }

    /**
     * IPoE gets neither the IP (the Hotspot would read it as a NAT address) nor a pool (isp drops it anyway).
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function replyRows(Account $account): array
    {
        $isIpoe = $account->service_type === ServiceType::Ipoe;
        $rows = [];

        if ($account->ip !== null && ! $isIpoe) {
            $rows[] = ['Framed-IP-Address', ':=', $account->ip->address];
        }

        if ($account->override_plan) {
            $rows[] = ['Mikrotik-Rate-Limit', ':=', self::rateLimit($account)->toString()];
        }

        if ($account->pool !== null && ! $isIpoe) {
            $rows[] = ['Framed-Pool', ':=', $account->pool->name];
        }

        foreach ($account->addressLists->sortBy('name') as $list) {
            $rows[] = ['Mikrotik-Address-List', '+=', $list->name];
        }

        foreach ($account->radiusAttributes->sortBy('id') as $attribute) {
            $rows[] = [$attribute->attribute, $attribute->op, $attribute->value];
        }

        return $rows;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string|int}>  $rows
     * @return list<array{username: string, attribute: string, op: string, value: string}>
     */
    private function owned(string $username, array $rows): array
    {
        return array_map(fn (array $row): array => [
            'username' => $username,
            'attribute' => $row[0],
            'op' => $row[1],
            'value' => (string) $row[2],
        ], $rows);
    }
}
