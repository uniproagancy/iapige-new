<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesChatMessage extends Model
{
    public const VISITOR = 'visitor';

    public const ASSISTANT = 'assistant';

    public const OPERATOR = 'operator';

    public const CALLBACK_PHONE_MARKER = "\u{E000}";

    protected $fillable = [
        'conversation_id',
        'role',
        'body',
        'cards',
        'staff_user_id',
        'client_message_id',
    ];

    protected function casts(): array
    {
        return ['cards' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SalesChatConversation::class, 'conversation_id');
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function redactedBody(): string
    {
        return str_replace(self::CALLBACK_PHONE_MARKER, __('sales_chat.redacted'), $this->body);
    }

    /** @return array{number: string, before: string, after: string}|null */
    public function callbackPhone(SalesChatConversation $conversation, ?string $number): ?array
    {
        if ($number === null || $this->role !== self::VISITOR || $this->callback_request_id === null
            || (int) $this->callback_request_id !== (int) $conversation->callback_request_id) {
            return null;
        }

        $parts = explode(self::CALLBACK_PHONE_MARKER, $this->body, 2);

        return count($parts) === 2 ? ['number' => $number, 'before' => $parts[0], 'after' => $parts[1]] : null;
    }

    public function scopeInboxIncoming(Builder $query): Builder
    {
        return $query->where(fn (Builder $message) => $message
            ->where('sales_chat_messages.role', self::VISITOR)
            ->orWhere(fn (Builder $callback) => $callback
                ->where('sales_chat_messages.role', self::ASSISTANT)
                ->whereHas('conversation', fn (Builder $conversation) => $conversation->whereNotNull('callback_request_id'))
                ->where(fn (Builder $confirmation) => $confirmation
                    ->whereNotNull('sales_chat_messages.callback_request_id')
                    ->orWhereIn('sales_chat_messages.body', [
                        __('sales_chat_terms.callback_received', [], 'ka'),
                        __('sales_chat_terms.callback_received', [], 'en'),
                    ]))));
    }

    public function scopeUnreadForStaff(Builder $query): Builder
    {
        return $query->inboxIncoming()
            ->whereNotExists(fn ($read) => $read->selectRaw('1')->from('sales_chat_reads')
                ->whereColumn('sales_chat_reads.conversation_id', 'sales_chat_messages.conversation_id')
                ->whereColumn('sales_chat_reads.last_read_message_id', '>=', 'sales_chat_messages.id'));
    }
}
