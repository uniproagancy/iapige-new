<?php

namespace App\Http\Controllers\SalesChat;

use App\Models\CallbackRequest;
use App\Models\SalesChatConversation;
use App\Models\SalesChatMessage;
use App\Services\SalesChat\SalesChatContact;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StaffController implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            static function (Request $request, Closure $next): Response {
                abort_unless(app(\App\Services\SalesChat\SalesChatSettings::class)->enabled(), 404);
                $response = $next($request);
                $response->headers->set('Cache-Control', 'private, no-store');

                return $response;
            },
        ];
    }

    public function index(Request $request): View
    {
        return $this->inbox($request, false);
    }

    public function leads(Request $request): View
    {
        return $this->inbox($request, true);
    }

    private function inbox(Request $request, bool $leadsOnly): View
    {
        $filters = $this->filters($request);
        $history = $request->validate(['history_anchor' => 'nullable|integer|min:1']);
        $historyAnchor = $request->integer('history', 1) > 1 ? ($history['history_anchor'] ?? null) : null;
        $actor = $request->user();
        $staffId = (int) $actor->getAuthIdentifier();
        $inbox = $this->visibleConversations($leadsOnly);
        $selected = $filters['conversation'] !== null
            ? $this->selectedConversation($inbox)->findOrFail($filters['conversation'])
            : null;
        $messages = $selected?->messages()
            ->with('staffUser:id,name')
            ->when($historyAnchor !== null, fn ($query) => $query->where('id', '<=', $historyAnchor))
            ->latest('id')
            ->simplePaginate(60, ['*'], 'history')
            ->withQueryString();
        if ($messages?->onFirstPage() && $messages->first()) {
            $messages->appends(['history_anchor' => $messages->first()->id]);
        }

        return view('admin.sales-chat.inbox', $this->queueData($inbox, $staffId, $leadsOnly, $filters) + [
            'selected' => $selected,
            'messages' => $messages,
            'callablePhone' => $selected ? $this->callablePhone($selected) : null,
            'replyId' => (string) Str::uuid(),
            'canReply' => $selected?->canReply($actor) ?? false,
        ]);
    }

    public function queue(Request $request): JsonResponse
    {
        $leadsOnly = $request->boolean('leads');
        $filters = $this->filters($request);
        $inbox = $this->visibleConversations($leadsOnly);
        $selected = $filters['conversation'] !== null
            ? $this->selectedConversation($inbox)->find($filters['conversation'])
            : null;
        $data = $this->queueData($inbox, (int) $request->user()->getAuthIdentifier(), $leadsOnly, $filters);

        return response()->json([
            'html' => view('admin.sales-chat.queue', $data)->render(),
            'leadHtml' => $selected ? view('admin.sales-chat.lead', $data + ['selected' => $selected])->render() : '',
            'unread_count' => $data['unreadCount'],
            'latestVisitorId' => $data['latestVisitorId'],
        ]);
    }

    private function visibleConversations(bool $leadsOnly): Builder
    {
        return SalesChatConversation::inInbox()
            ->when($leadsOnly, fn (Builder $query) => $query->whereNotNull('callback_request_id'));
    }

    private function selectedConversation(Builder $inbox): Builder
    {
        return (clone $inbox)->with(['lead.product', 'lead.handler:id,name', 'assignee:id,name']);
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'conversation' => 'nullable|integer|min:1',
            'filter' => 'nullable|in:all,unread,mine',
            'conversations' => 'nullable|integer|min:1',
        ]);

        return [
            'conversation' => isset($validated['conversation']) ? (int) $validated['conversation'] : null,
            'filter' => $validated['filter'] ?? 'all',
            'conversations' => (int) ($validated['conversations'] ?? 1),
        ];
    }

    private function queueData(Builder $inbox, int $staffId, bool $leadsOnly, array $filters): array
    {
        $inboxRoute = $leadsOnly ? 'admin.sales-chat.leads' : 'admin.sales-chat.index';
        $unreadCount = (clone $inbox)->unreadFor($staffId)->count();
        $conversations = $inbox->with([
            'assignee:id,name', 'latestMessage.staffUser:id,name',
            'lead:id,status,name,order_id,handled_by,handled_at', 'lead.handler:id,name',
        ])
            ->withMax('messages as latest_message_id', 'id')
            ->withExists(['messages as has_unread' => fn (Builder $messages) => $messages->unreadForStaff($staffId)])
            ->when($filters['filter'] === 'unread', fn (Builder $query) => $query->unreadFor($staffId))
            ->when($filters['filter'] === 'mine', fn (Builder $query) => $query->where('assigned_to', $staffId))
            ->orderByDesc('latest_message_id')
            ->latest('created_at')
            ->latest('id')
            ->simplePaginate(25, ['*'], 'conversations', $filters['conversations'])
            ->withPath(route($inboxRoute))
            ->appends(['conversation' => $filters['conversation'], 'filter' => $filters['filter']]);

        return [
            'conversations' => $conversations,
            'selectedId' => $filters['conversation'],
            'activeFilter' => $filters['filter'],
            'unreadCount' => $unreadCount,
            'latestVisitorId' => (int) SalesChatMessage::query()
                ->where('role', SalesChatMessage::VISITOR)->latest('id')->value('id'),
            'leadsOnly' => $leadsOnly,
            'inboxRoute' => $inboxRoute,
        ];
    }

    public function messages(Request $request, SalesChatConversation $conversation): JsonResponse
    {
        $validated = $request->validate(['after' => 'nullable|integer|min:0']);
        $messages = $conversation->messages()
            ->with('staffUser:id,name')
            ->where('id', '>', $validated['after'] ?? 0)
            ->orderBy('id')
            ->limit(60)
            ->get();
        $phone = $this->callablePhone($conversation);

        return response()->json([
            'callback_phone' => $phone,
            'messages' => $messages->map(fn (SalesChatMessage $message) => [
                'id' => $message->id,
                'role' => $message->role,
                'author' => $message->staffUser?->name ?? __('sales_chat_admin.roles.'.$message->role),
                'body' => $message->redactedBody(),
                'callback_phone' => $message->callbackPhone($conversation, $phone),
                'cards' => $message->cards ?? [],
                'time' => $message->created_at->format('d.m H:i'),
            ]),
        ]);
    }

    private function callablePhone(SalesChatConversation $conversation): ?string
    {
        if ($conversation->contact_consent_at === null) {
            return null;
        }

        $lead = $conversation->lead;

        return $lead?->page === 'sales-chat' ? SalesChatContact::mobile((string) $lead->phone) : null;
    }

    public function markRead(Request $request, SalesChatConversation $conversation): Response
    {
        $validated = $request->validate(['through' => 'required|integer|min:1']);
        $messageId = (int) $validated['through'];
        abort_unless($conversation->messages()->whereKey($messageId)->exists(), 422);
        $conversation->acknowledgeMessages((int) $request->user()->getAuthIdentifier(), $messageId);

        return response()->noContent();
    }

    public function claim(Request $request, SalesChatConversation $conversation): RedirectResponse
    {
        DB::transaction(function () use ($request, $conversation): void {
            $locked = SalesChatConversation::lockForUpdate()->findOrFail($conversation->id);
            $actor = (int) $request->user()->getAuthIdentifier();
            if ($locked->assigned_to !== null && (int) $locked->assigned_to !== $actor) {
                throw ValidationException::withMessages(['conversation' => __('sales_chat_admin.already_claimed')]);
            }
            if ($locked->assigned_to === null) {
                $locked->update(['assigned_to' => $actor]);
            }
        }, 3);

        return $this->returnToConversation($request, $conversation);
    }

    public function markCalled(Request $request, SalesChatConversation $conversation): RedirectResponse
    {
        DB::transaction(function () use ($request, $conversation): void {
            $locked = SalesChatConversation::lockForUpdate()->findOrFail($conversation->id);
            abort_if($locked->contact_consent_at === null || $locked->callback_request_id === null, 404);

            $lead = $locked->lead()->where('page', 'sales-chat')->lockForUpdate()->firstOrFail();
            if ($lead->isOpen() && $lead->order_id === null) {
                $lead->update([
                    'status' => CallbackRequest::CALLED,
                    'handled_by' => $request->user()->getAuthIdentifier(),
                    'handled_at' => now(),
                ]);
            }
        }, 3);

        return $this->returnToConversation($request, $conversation);
    }

    public function reply(Request $request, SalesChatConversation $conversation): RedirectResponse
    {
        $validated = $request->validate(['body' => 'required|string|max:2000', 'client_message_id' => 'required|uuid']);
        $body = trim($validated['body']);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => __('sales_chat_admin.empty_reply')]);
        }

        DB::transaction(function () use ($request, $conversation, $validated, $body): void {
            $locked = SalesChatConversation::lockForUpdate()->findOrFail($conversation->id);
            $user = $request->user();
            abort_unless($locked->canReply($user), 403);
            $actor = (int) $user->getAuthIdentifier();

            $existing = $locked->messages()->where('client_message_id', $validated['client_message_id'])->first();
            if ($existing !== null) {
                if ($existing->role !== SalesChatMessage::OPERATOR || (int) $existing->staff_user_id !== $actor || $existing->body !== $body) {
                    throw ValidationException::withMessages(['body' => __('sales_chat_admin.retry_reply')]);
                }

                return;
            }

            $locked->messages()->create([
                'role' => SalesChatMessage::OPERATOR,
                'body' => $body,
                'staff_user_id' => $actor,
                'client_message_id' => $validated['client_message_id'],
            ]);
            $locked->touch();
        }, 3);

        return $this->returnToConversation($request, $conversation);
    }

    private function returnToConversation(Request $request, SalesChatConversation $conversation): RedirectResponse
    {
        $route = $request->boolean('leads') ? 'admin.sales-chat.leads' : 'admin.sales-chat.index';

        return redirect()->route($route, [
            'conversation' => $conversation->id,
            'filter' => in_array($request->input('filter'), ['unread', 'mine'], true) ? $request->input('filter') : null,
        ]);
    }
}
