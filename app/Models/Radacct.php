<?php

namespace App\Models;

use App\Support\Units;
use Carbon\CarbonInterface;
use Database\Factories\RadacctFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A FreeRADIUS accounting session (radacct). FreeRADIUS writes these rows; the app only reads them.
 */
class Radacct extends Model
{
    /** @use HasFactory<RadacctFactory> */
    use HasFactory;

    protected $table = 'radacct';

    protected $primaryKey = 'radacctid';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acctstarttime' => 'datetime',
            'acctupdatetime' => 'datetime',
            'acctstoptime' => 'datetime',
            'acctsessiontime' => 'integer',
            'acctinputoctets' => 'integer',
            'acctoutputoctets' => 'integer',
        ];
    }

    /**
     * Sessions the router has not stopped yet.
     *
     * @param  Builder<Radacct>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('acctstoptime');
    }

    /**
     * What a customer used in sessions that started at or after $since (every session when null), the way isp
     * counts its limits.
     *
     * @return array{bytes: int, seconds: int}
     */
    public static function usage(string $username, ?CarbonInterface $since): array
    {
        $row = static::query()
            ->where('username', $username)
            ->when($since !== null, fn (Builder $query) => $query->where('acctstarttime', '>=', $since))
            ->selectRaw('COALESCE(SUM(acctinputoctets), 0) + COALESCE(SUM(acctoutputoctets), 0) AS bytes, COALESCE(SUM(acctsessiontime), 0) AS seconds')
            ->toBase()
            ->first();

        return ['bytes' => (int) ($row->bytes ?? 0), 'seconds' => (int) ($row->seconds ?? 0)];
    }

    /**
     * What the session tables show.
     *
     * @param  array<string, string>  $routers  router names by IP (Nas::names())
     * @return array{id: int, username: string, router: string, ip: string, mac: string, started: string, duration: string, download: string, upload: string}
     */
    public function forTable(array $routers, string $dateFormat): array
    {
        return [
            'id' => $this->radacctid,
            'username' => $this->username,
            'router' => $routers[$this->nasipaddress] ?? $this->nasipaddress,
            'ip' => $this->framedipaddress,
            'mac' => $this->callingstationid,
            'started' => $this->acctstarttime?->format($dateFormat) ?? '',
            'duration' => $this->acctstarttime === null ? '' : Units::duration((int) $this->acctstarttime->diffInSeconds(now())),
            // The router's input is what the customer sent.
            'download' => Units::bytes((int) $this->acctoutputoctets),
            'upload' => Units::bytes((int) $this->acctinputoctets),
        ];
    }
}
