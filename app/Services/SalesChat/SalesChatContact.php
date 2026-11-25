<?php

namespace App\Services\SalesChat;

use App\Models\CallbackRequest;
use App\Models\SalesChatConversation;
use App\Models\SalesChatMessage;
use App\Support\Phone;

class SalesChatContact
{
    private const PHONE_PATTERN = '(?<![+\pL\pN])\+?\d(?:[\h().\p{Pd}]*\d){8,}(?![\pL\pN]|[\h().\p{Pd}]*\d)';

    public function __construct(private SalesCatalog $catalog) {}

    public static function mobile(string $value): ?string
    {
        $phone = Phone::dial($value);

        return $phone !== null && preg_match('/^\+9955\d{8}$/D', $phone) === 1 ? $phone : null;
    }

    public function phoneIn(string $body): ?string
    {
        $body = preg_replace([
            '~(?:https?://|www\.)\S+~iu',
            '~\S+@\S+~u',
        ], ' ', $body) ?? '';
        preg_match_all('~'.self::PHONE_PATTERN.'~u', $body, $matches);

        foreach ($matches[0] as $candidate) {
            $phone = self::mobile($candidate);
            if ($phone !== null) {
                return $phone;
            }
        }

        return null;
    }

    public function markPhone(string $body, string $number): string
    {
        $marked = false;
        $pattern = '~(?:https?://|www\.)\S+|\S+@\S+|('.self::PHONE_PATTERN.')~iu';

        return preg_replace_callback($pattern, static function (array $match) use ($number, &$marked): string {
            if (! $marked && isset($match[1]) && self::mobile($match[1]) === $number) {
                $marked = true;

                return SalesChatMessage::CALLBACK_PHONE_MARKER;
            }

            return $match[0];
        }, $body) ?? $body;
    }

    public function explicitName(string $body, bool $withPhone): ?string
    {
        $body = preg_replace('~'.self::PHONE_PATTERN.'~u', '', $body) ?? '';
        $word = "[\\pL\\pM]+(?:[-’'][\\pL\\pM]+)*";
        $introduction = $withPhone ? 'მე\h+ვარ\h+|' : '';
        $pattern = '~(?:\A|[,.;!?]\h*)(?:'.$introduction.'ჩემი\h+სახელია\h+|სახელი(?:\h+და\h+გვარი)?\h*:\h*|my\h+name\h+is\h+|name\h*:\h*)'
            .'('.$word.'(?:\h+'.$word.'){0,3})[,\h!.]*\z~iuD';

        if (preg_match($pattern, trim($body, " \t\n\r\0\x0B+()"), $parts) !== 1 || mb_strlen($parts[1]) > 80) {
            return null;
        }

        return $parts[1];
    }

    public function updateGuestName(SalesChatConversation $chat, string $name): bool
    {
        return $chat->lead()
            ->where('page', 'sales-chat')
            ->where('status', CallbackRequest::NEW)
            ->whereNull('order_id')
            ->whereIn('name', [__('sales_chat.guest', [], 'ka'), __('sales_chat.guest', [], 'en')])
            ->update(['name' => $name]) === 1;
    }

    public function create(
        SalesChatConversation $chat,
        string $phone,
        ?string $name,
        ?int $userId,
        ?array $product,
        ?string $comment = null,
    ): void {
        $name = trim((string) $name);
        $lead = CallbackRequest::create([
            'user_id' => $userId,
            'product_id' => $product['id'] ?? null,
            'product_name' => isset($product['name']) ? mb_substr($product['name'], 0, 255) : null,
            'name' => $name === '' ? __('sales_chat.guest') : mb_substr($name, 0, 80),
            'phone' => $phone,
            'status' => CallbackRequest::NEW,
            'page' => 'sales-chat',
            'quantity' => 1,
            'comment' => $comment,
        ]);
        $chat->forceFill([
            'callback_request_id' => $lead->id,
            'contact_consent_at' => now(),
        ])->save();
    }

    public function fromMessage(
        SalesChatConversation $chat,
        SalesChatMessage $visitor,
        string $phone,
        ?string $name,
        ?int $userId,
        ?string $productSlug,
    ): void {
        $recent = $chat->messages()->where('id', '<', $visitor->id)
            ->latest('id')->limit(10)->get(['role', 'body', 'cards']);
        $recommendation = $recent->first(fn (SalesChatMessage $turn) =>
            $turn->role === SalesChatMessage::ASSISTANT && ! empty($turn->cards));
        $category = collect($recommendation?->cards)->firstWhere('type', 'category');
        $previous = $recent->firstWhere('role', SalesChatMessage::VISITOR);
        $request = trim(str_replace(__('sales_chat.redacted'), '', $visitor->redactedBody()), " \t\n\r\0\x0B,.;:()-");
        $details = [];
        if (is_string($category['name'] ?? null) && $category['name'] !== '') {
            $details[] = __('sales_chat_terms.callback_interest_category', ['category' => mb_substr($category['name'], 0, 200)]);
        }
        if ($previous !== null && $previous->body !== $request) {
            $details[] = __('sales_chat_terms.callback_interest_request', ['request' => mb_substr($previous->redactedBody(), 0, 400)]);
        }
        if ($request !== '') {
            $details[] = mb_substr($request, 0, 400);
        }

        $product = $productSlug === null ? null : $this->catalog->productFromSlug($productSlug);
        $this->create($chat, $phone, $name, $userId, $product, $details === [] ? null : implode("\n", $details));
        $visitor->forceFill(['callback_request_id' => $chat->callback_request_id])->save();

        $receipt = __('sales_chat_terms.callback_received');
        if (trim((string) $name) === '') {
            $receipt .= "\n\n".__('sales_chat_terms.callback_optional_name');
        }
        $chat->messages()->create(['role' => SalesChatMessage::ASSISTANT, 'body' => $receipt]);
    }
}
