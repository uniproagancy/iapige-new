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

    public function scopeUnreadFor(Builder $query, int $staffId): Builder
    {
        return $query->whereHas('messages', fn (Builder $messages) => $messages->unreadForStaff($staffId));
    }

    public function acknowledgeMessages(int $staffId, int $lastMessageId): void
    {
        $marker = ['conversation_id' => $this->id, 'user_id' => $staffId];
        DB::table('sales_chat_reads')->insertOrIgnore($marker + ['last_read_message_id' => $lastMessageId]);
        DB::table('sales_chat_reads')->where($marker)
            ->where('last_read_message_id', '<', $lastMessageId)
            ->update(['last_read_message_id' => $lastMessageId]);
    }

    public function canReply(User $user): bool
    {
        return $user->isStaff() && $this->assigned_to !== null
            && ($user->isAdmin() || (int) $this->assigned_to === (int) $user->getKey());
    }
}
