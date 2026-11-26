<?php

namespace App\Http\Controllers\SalesChat;

use App\Http\Controllers\Controller;
use App\Services\SalesChat\SalesChatKnowledge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class KnowledgeController extends Controller
{
    public function show(SalesChatKnowledge $knowledge): Response
    {
        return response()->view('admin.sales-chat.knowledge', ['knowledge' => $knowledge->snapshot()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, SalesChatKnowledge $knowledge): RedirectResponse
    {
        $data = $request->validate([
            'delivery_ka' => ['required', 'string', 'max:1500'],
            'delivery_en' => ['required', 'string', 'max:1500'],
            'facts' => ['nullable', 'string', 'max:6000'],
            'topics' => ['sometimes', 'array:'.implode(',', SalesChatKnowledge::TOPICS)],
            'topics.*' => ['nullable', 'string', 'max:'.SalesChatKnowledge::TOPIC_LENGTH],
            'examples_present' => ['sometimes', 'accepted'],
            'examples' => ['sometimes', 'array', 'max:'.SalesChatKnowledge::EXAMPLE_LIMIT],
            'examples.*' => ['array:question,answer'],
            'examples.*.question' => ['nullable', 'required_with:examples.*.answer', 'string', 'max:'.SalesChatKnowledge::QUESTION_LENGTH],
            'examples.*.answer' => ['nullable', 'required_with:examples.*.question', 'string', 'max:'.SalesChatKnowledge::ANSWER_LENGTH],
        ], attributes: [
            'examples.*.question' => __('sales_chat_knowledge.question'),
            'examples.*.answer' => __('sales_chat_knowledge.answer'),
        ]);
        $knowledge->save(
            $data['delivery_ka'],
            $data['delivery_en'],
            $data['facts'] ?? null,
            $data['topics'] ?? null,
            $data['examples'] ?? (isset($data['examples_present']) ? [] : null),
        );

        return to_route('admin.sales-chat.knowledge.show')->with('status', __('sales_chat_knowledge.saved'));
    }
}
