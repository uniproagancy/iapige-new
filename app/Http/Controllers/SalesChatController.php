<?php

namespace App\Http\Controllers;

use App\Models\SalesChatConversation;
use App\Models\SalesChatMessage;
use App\Services\SalesChat\SalesAssistant;
use App\Services\SalesChat\SalesCatalog;
use App\Services\SalesChat\SalesChatContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesChatController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->response($this->conversation($request));
    }

    public function message(Request $request, SalesAssistant $assistant, SalesChatContact $contact): JsonResponse
    {
        abort_unless(app(\App\Services\SalesChat\SalesChatSettings::class)->enabled(), 404);
        $input = $request->validate([
            'message' => ['required', 'string', 'max:1200'],
            'client_message_id' => ['required', 'uuid'],
            'product_slug' => ['nullable', 'string', 'max:190', 'regex:/^[\p{L}\p{N}][\p{L}\p{N}._~-]*$/uD'],
            'category_slug' => ['nullable', 'string', 'max:190', 'regex:/^[\p{L}\p{N}][\p{L}\p{N}._~-]*$/uD'],
        ]);
        $conversation = $this->conversation($request);
        $phone = $contact->phoneIn($input['message']);
        $visitor = DB::transaction(function () use ($conversation, $request, $input, $contact, $phone): ?SalesChatMessage {
            $chat = SalesChatConversation::lockForUpdate()->findOrFail($conversation->id);
            if ($chat->messages()->where('client_message_id', $input['client_message_id'])->exists()) {
                return null;
            }
            $name = $phone !== null || $chat->callback_request_id !== null
                ? $contact->explicitName($input['message'], $phone !== null)
                : null;
            $body = str_replace(SalesChatMessage::CALLBACK_PHONE_MARKER, __('sales_chat.redacted'), $input['message']);
            if ($name !== null) {
                $body = str_replace($name, __('sales_chat.redacted'), $body);
            }
            $existingContact = $phone !== null && $chat->contact_consent_at !== null && $chat->lead?->phone === $phone;
            if ($phone !== null && ($chat->callback_request_id === null || $existingContact)) {
                $body = $contact->markPhone($body, $phone);
            }
            $message = $chat->messages()->create([
                'role' => SalesChatMessage::VISITOR,
                'body' => $this->redact($body),
                'client_message_id' => $input['client_message_id'],
            ]);
            if ($existingContact) {
                $message->forceFill(['callback_request_id' => $chat->callback_request_id])->save();
            }
            $chat->touch();

            if ($phone !== null && $chat->callback_request_id === null) {
                $contact->fromMessage(
                    $chat,
                    $message,
                    $phone,
                    $name ?? $request->user()?->name,
                    $request->user()?->id,
                    $input['product_slug'] ?? null,
                );

                return null;
            }

            if ($phone === null && $name !== null && $chat->contact_consent_at !== null
                && $contact->updateGuestName($chat, $name)) {
                $chat->messages()->create([
                    'role' => SalesChatMessage::ASSISTANT,
                    'body' => __('sales_chat_terms.callback_name_received'),
                ]);

                return null;
            }

            if ($phone !== null) {
                $chat->messages()->create([
                    'role' => SalesChatMessage::ASSISTANT,
                    'body' => __('sales_chat_terms.callback_already_received'),
                ]);

                return null;
            }

            return $message;
        });

        if ($visitor !== null && $this->mode($conversation->refresh()) === 'assistant') {
            $history = $conversation->messages()->where('id', '<=', $visitor->id)
                ->latest('id')->limit(10)->get(['role', 'body', 'cards'])->reverse()->values()
                ->map(function (SalesChatMessage $message): array {
                    $turn = ['role' => $message->role, 'body' => $this->redact($message->redactedBody())];
                    foreach (['product' => 'products', 'category' => 'categories'] as $type => $field) {
                        $cards = collect($message->cards)->where('type', $type)
                            ->filter(fn ($card) => is_int($card['id'] ?? null) && is_string($card['name'] ?? null))
                            ->map(fn (array $card): array => ['id' => $card['id'], 'name' => $card['name']])
                            ->take(5)->values()->all();
                        if ($cards !== []) {
                            $turn[$field] = $cards;
                        }
                    }

                    return $turn;
                })->all();
            $answer = $assistant->answer(
                $history,
                $input['product_slug'] ?? null,
                $input['category_slug'] ?? null,
                $conversation->callback_request_id !== null,
            );
            DB::transaction(function () use ($conversation, $visitor, $answer): void {
                $chat = SalesChatConversation::lockForUpdate()->findOrFail($conversation->id);
                if ($this->mode($chat) !== 'assistant' || $chat->messages()->where('id', '>', $visitor->id)->exists()) {
                    return;
                }
                $chat->messages()->create([
                    'role' => 'assistant',
                    'body' => $answer['body'],
                    'cards' => $answer['cards'],
                ]);
                $chat->touch();
            });
        }

        return $this->response($conversation->refresh());
    }

    public function lead(Request $request, SalesCatalog $catalog, SalesChatContact $contact): JsonResponse
    {
        abort_unless(app(\App\Services\SalesChat\SalesChatSettings::class)->enabled(), 404);
        $input = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'product_id' => ['required', 'integer', 'min:1'],
            'name' => ['nullable', 'string', 'max:80'],
            'consent' => ['required', 'accepted'],
        ]);
        $phone = SalesChatContact::mobile($input['phone']);
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => __('sales_chat.phone_invalid')]);
        }
        $conversation = $this->conversation($request);
        $name = trim($input['name'] ?? '') ?: trim((string) $request->user()?->name);
        DB::transaction(function () use ($request, $catalog, $contact, $input, $phone, $name, $conversation): void {
            $chat = SalesChatConversation::lockForUpdate()->findOrFail($conversation->id);
            if ($chat->callback_request_id) {
                return;
            }
            $product = $catalog->product((int) $input['product_id']);
            if ($product === null) {
                throw ValidationException::withMessages(['product_id' => __('sales_chat.product_unavailable')]);
            }
            $contact->create($chat, $phone, $name, $request->user()?->id, $product);
            $confirmation = $chat->messages()->create([
                'role' => SalesChatMessage::ASSISTANT,
                'body' => __('sales_chat_terms.callback_received'),
            ]);
            $confirmation->forceFill(['callback_request_id' => $chat->callback_request_id])->save();
        });

        return $this->response($conversation->refresh());
    }

    private function conversation(Request $request): SalesChatConversation
    {
        abort_unless(app(\App\Services\SalesChat\SalesChatSettings::class)->enabled(), 404);
        $session = $request->session();
        $hash = $session->get('sales_chat_owner');
        if (! is_string($hash)) {
            $hash = hash_hmac('sha256', 'iapi-sales-chat:'.$session->getId(), (string) config('app.key'));
            $session->put('sales_chat_owner', $hash);
        }

        return SalesChatConversation::firstOrCreate(['session_hash' => $hash]);
    }

    private function response(SalesChatConversation $chat): JsonResponse
    {
        $messages = $chat->messages()->latest('id')->limit(60)->get()->reverse()->values()
            ->map(fn (SalesChatMessage $message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'body' => $message->redactedBody(),
                'cards' => $message->cards ?? [],
            ])->all();
        if ($messages === []) {
            $messages[] = ['id' => 0, 'role' => 'assistant', 'body' => __('sales_chat.welcome'), 'cards' => []];
        }

        return response()->json([
            'messages' => $messages,
            'mode' => $this->mode($chat),
            'lead_saved' => $chat->callback_request_id !== null,
        ])->header('Cache-Control', 'private, no-store');
    }

    private function mode(SalesChatConversation $chat): string
    {
        return $chat->assigned_to ? 'operator' : 'assistant';
    }

    private function redact(string $body): string
    {
        $patterns = [
            '~(?:https?://|www\.)\S+~iu',
            '~[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}~iu',
            '~(?<!\d)\+?\d(?:[\s().\p{Pd}]*\d){8,}(?!\d)~u',
        ];

        return trim(preg_replace($patterns, __('sales_chat.redacted'), $body) ?? '');
    }
}
