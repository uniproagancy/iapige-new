<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class SalesChatConversation extends Model
{
    protected $fillable = [
        'session_hash',
        'assigned_to',
        'handoff_requested_at',
        'callback_request_id',
        'contact_consent_at',
    ];

    protected $hidden = ['session_hash'];

    protected function casts(): array
    {
        return ['handoff_requested_at' => 'datetime', 'contact_consent_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SalesChatMessage::class, 'conversation_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(SalesChatMessage::class, 'conversation_id')->latestOfMany();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CallbackRequest::class, 'callback_request_id');
    }

    public function scopeInInbox(Builder $query): Builder
    {
        return $query->where(fn (Builder $visible) => $visible
            ->has('messages')
            ->orWhereNotNull('handoff_requested_at')
            ->orWhereNotNull('callback_request_id'));
    }

    public function scopeUnreadFor(Builder $query): Builder
    {
        return $query->whereHas('messages', fn (Builder $messages) => $messages->unreadForStaff());
    }

    public function scopeWithReaderName(Builder $query): Builder
    {
        return $query->addSelect(['reader_name' => DB::table('sales_chat_reads')
            ->join('users', 'users.id', '=', 'sales_chat_reads.user_id')
            ->select('users.name')
            ->whereColumn('sales_chat_reads.conversation_id', 'sales_chat_conversations.id')
            ->orderByDesc('sales_chat_reads.last_read_message_id')
            ->orderBy('sales_chat_reads.user_id')
            ->limit(1)]);
    }

    public function acknowledgeMessages(int $staffId, int $lastMessageId): void
    {
        DB::transaction(function () use ($staffId, $lastMessageId): void {
            $conversation = static::query()->lockForUpdate()->findOrFail($this->id);
            $incomingId = (int) $conversation->messages()->inboxIncoming()->where('id', '<=', $lastMessageId)->max('id');
            $receipts = DB::table('sales_chat_reads')->where('conversation_id', $conversation->id);

            // A second staff member opening the same messages must not replace the reader.
            if ($incomingId <= (int) $receipts->max('last_read_message_id')) {
                return;
            }

            $receipts->updateOrInsert(
                ['conversation_id' => $conversation->id, 'user_id' => $staffId],
                ['last_read_message_id' => $incomingId],
            );
        }, 3);
    }

    public function canReply(User $user): bool
    {
        return $user->isStaff() && $this->assigned_to !== null
            && ($user->isAdmin() || (int) $this->assigned_to === (int) $user->getKey());
    }
}
