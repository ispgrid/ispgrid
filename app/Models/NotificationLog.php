<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Notifications\OutgoingMessage;
use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message: what was sent to whom and what the provider said. The once_key (unique) is what keeps a reminder
 * from going out twice. The template and values are copied from the rule when the row is made, so a Retry sends
 * exactly the same message.
 */
#[Fillable(['notification_rule_id', 'account_id', 'provider', 'channel', 'to', 'template', 'values', 'values_trimmed',
    'preview', 'status', 'error', 'provider_message_id', 'attempts', 'once_key', 'sent_at', 'user_id'])]
class NotificationLog extends Model
{
    /** @use HasFactory<NotificationLogFactory> */
    use HasFactory;

    protected $table = 'notification_log';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationStatus::class,
            'template' => 'array',
            'values' => 'array',
            'values_trimmed' => 'boolean',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<NotificationRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(NotificationRule::class, 'notification_rule_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function toMessage(): OutgoingMessage
    {
        return new OutgoingMessage($this->to, $this->template ?? [], $this->values ?? [], $this->preview, (string) $this->id);
    }
}
