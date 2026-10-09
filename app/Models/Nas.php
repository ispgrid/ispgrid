<?php

namespace App\Models;

use App\Support\RouterOsVersion;
use Database\Factories\NasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A MikroTik router (FreeRADIUS client). FreeRADIUS loads these rows at start.
 */
#[Fillable(['nasname', 'shortname', 'type', 'secret', 'description', 'routeros_version', 'require_message_auth'])]
#[Hidden(['secret'])]
class Nas extends Model
{
    /** @use HasFactory<NasFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'nas';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['require_message_auth' => 'boolean'];
    }

    /**
     * The secret is never logged.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['shortname', 'nasname', 'routeros_version', 'require_message_auth', 'description'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Customers bound to this router (Isp-Nas).
     *
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * The Blast-RADIUS warning when this router's RouterOS is too old to send Message-Authenticator.
     */
    public function versionWarning(): ?string
    {
        return RouterOsVersion::warningFor($this->routeros_version);
    }
}
