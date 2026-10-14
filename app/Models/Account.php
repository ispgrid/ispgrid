<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A customer account. The source of truth for its FreeRADIUS rows (SyncAccountToRadius rebuilds them).
 * The password is stored in plain text like radcheck: PAP, CHAP and MS-CHAP need the cleartext.
 */
#[Fillable(['username', 'password', 'title', 'fullname', 'company', 'mobile', 'whatsapp', 'alt_number', 'email',
    'tax_id', 'business_id', 'remarks', 'notifications_off', 'address_id', 'connection_type_id', 'user_id',
    'service_type', 'mac_type', 'plan_id', 'nas_id', 'status_id', 'expiry', 'usage_since', 'ip_id', 'pool_id',
    'override_plan', 'override_upload', 'override_download', 'override_burst_upload', 'override_burst_download',
    'override_threshold_upload', 'override_threshold_download', 'override_burst_time', 'override_priority',
    'override_min_upload', 'override_min_download'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The database defaults, so a customer created in this request does not log them as changed on its first
     * update.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'mac_type' => 1,
        'status_id' => 1,
        'override_plan' => false,
        'notifications_off' => false,
    ];

    /**
     * Every column staff edit, never the password. The address, address lists and custom attributes live in
     * other tables; the form logs their changes itself.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly([
            'username', 'title', 'fullname', 'company', 'mobile', 'whatsapp', 'alt_number', 'email',
            'tax_id', 'business_id', 'remarks', 'notifications_off', 'connection_type_id',
            'service_type', 'mac_type', 'plan_id', 'nas_id', 'status_id', 'expiry', 'usage_since', 'ip_id', 'pool_id',
            'override_plan', 'override_upload', 'override_download', 'override_burst_upload', 'override_burst_download',
            'override_threshold_upload', 'override_threshold_download', 'override_burst_time', 'override_priority',
            'override_min_upload', 'override_min_download',
        ])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_id' => AccountStatus::class,
            'service_type' => ServiceType::class,
            'expiry' => 'datetime',
            'usage_since' => 'datetime',
            'override_plan' => 'boolean',
            'notifications_off' => 'boolean',
            'mac_type' => 'integer',
            'plan_id' => 'integer',
            'nas_id' => 'integer',
            'ip_id' => 'integer',
            'pool_id' => 'integer',
            'connection_type_id' => 'integer',
            'address_id' => 'integer',
            'override_burst_time' => 'integer',
            'override_priority' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<Pool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    /**
     * @return BelongsTo<Ip, $this>
     */
    public function ip(): BelongsTo
    {
        return $this->belongsTo(Ip::class);
    }

    /**
     * The router this account is bound to, if any.
     *
     * @return BelongsTo<Nas, $this>
     */
    public function nas(): BelongsTo
    {
        return $this->belongsTo(Nas::class);
    }

    /**
     * @return BelongsTo<Address, $this>
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /**
     * @return BelongsTo<ConnectionType, $this>
     */
    public function connectionType(): BelongsTo
    {
        return $this->belongsTo(ConnectionType::class);
    }

    /**
     * The future customer-portal login (unused in the MVP).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<Ipoe, $this>
     */
    public function ipoe(): HasOne
    {
        return $this->hasOne(Ipoe::class);
    }

    /**
     * @return BelongsToMany<AddressList, $this>
     */
    public function addressLists(): BelongsToMany
    {
        return $this->belongsToMany(AddressList::class, 'account_address_list');
    }

    /**
     * Custom RADIUS reply attributes of this account.
     *
     * @return HasMany<AccountAttribute, $this>
     */
    public function radiusAttributes(): HasMany
    {
        return $this->hasMany(AccountAttribute::class);
    }

    /**
     * @return HasMany<NotificationLog, $this>
     */
    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }
}
