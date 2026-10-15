<?php

namespace App\Models;

use App\Enums\LoginResult;
use Database\Factories\RadpostauthFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One login attempt as FreeRADIUS logged it (radpostauth): the result, the real reason, the router and the MAC.
 * The pass column is never shown (app-integration.md section 5).
 */
class Radpostauth extends Model
{
    /** @use HasFactory<RadpostauthFactory> */
    use HasFactory;

    protected $table = 'radpostauth';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['authdate' => 'datetime'];
    }

    public function result(): LoginResult
    {
        return LoginResult::fromReply((string) $this->reply);
    }

    /**
     * What the login tables show.
     *
     * @param  array<string, string>  $routers  router names by IP (Nas::names())
     * @return array{id: int, username: string, when: string, result: string, color: string, reason: string, router: string, mac: string}
     */
    public function forTable(array $routers, string $dateFormat): array
    {
        $result = $this->result();

        return [
            'id' => $this->id,
            'username' => $this->username,
            'when' => $this->authdate->format($dateFormat),
            'result' => $result->label(),
            'color' => $result->color(),
            'reason' => (string) $this->reply_msg,
            'router' => $routers[(string) $this->nasipaddress] ?? (string) $this->nasipaddress,
            'mac' => (string) $this->mac,
        ];
    }
}
