<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Notifications\OutgoingMessage;
use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message: what was sent to whom and what the provider said. The once_key (unique) is what keeps a reminder
 * from going out twice. The template and values are copied from the rule when the row is made, so a Retry sends
 * exactly the same message. A message with a password (has_secret) holds ******** in its values and preview, and
 * the password encrypted in secret until the send is final (Sent or Failed); then secret is emptied and the message
 * cannot be retried.
 *
 * @property array<string, string>|null $template
 * @property list<string>|null $values
 */
#[Fillable(['notification_rule_id', 'account_id', 'provider', 'channel', 'to', 'template', 'values', 'values_trimmed',
    'preview', 'status', 'error', 'provider_message_id', 'attempts', 'once_key', 'sent_at', 'user_id', 'secret', 'has_secret'])]
#[Hidden(['secret'])]
class NotificationLog extends Model
{
    /** @use HasFactory<NotificationLogFactory> */
    use HasFactory;

    protected $table = 'notification_log';

    /** What the values and preview show in place of a password. */
    public const SECRET_MASK = '********';

    /** The password a Send test puts in place of a real one. */
    public const SAMPLE_SECRET = 'Sample2345';

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
            'secret' => 'encrypted',
            'has_secret' => 'boolean',
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

    /**
     * The message to send: a held password goes back in where the values and preview show the mask.
     */
    public function toMessage(): OutgoingMessage
    {
        $values = $this->values ?? [];
        $preview = $this->preview;

        if ($this->has_secret && $this->secret !== null) {
            $values = array_map(fn (string $value): string => $value === self::SECRET_MASK ? $this->secret : $value, $values);
            $preview = $preview === null ? null : str_replace(self::SECRET_MASK, $this->secret, $preview);
        }

        return new OutgoingMessage($this->to, $this->template ?? [], $values, $preview, (string) $this->id);
    }

    /**
     * Whether staff may send it again: a failed message, unless it carried a password (that one is gone).
     */
    public function canRetry(): bool
    {
        return $this->status === NotificationStatus::Failed && ! $this->has_secret;
    }
}
