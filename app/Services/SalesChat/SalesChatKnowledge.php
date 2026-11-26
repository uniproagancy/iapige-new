<?php

namespace App\Services\SalesChat;

use App\Models\Setting;

class SalesChatKnowledge
{
    private const SETTING_KEY = 'sales_chat.knowledge';

    public const TOPICS = ['payment', 'pickup', 'warranty', 'returns', 'contact'];

    public const TOPIC_LENGTH = 1500;

    public const EXAMPLE_LIMIT = 40;

    public const QUESTION_LENGTH = 280;

    public const ANSWER_LENGTH = 1200;

    /**
     * @return array{delivery_ka: string, delivery_en: string, facts: string, topics: array<string, string>, examples: list<array{question: string, answer: string}>}
     */
    public function snapshot(): array
    {
        $stored = Setting::query()->where('key', self::SETTING_KEY)->value('value');
        $knowledge = is_string($stored) ? json_decode($stored, true) : null;

        return [
            'delivery_ka' => $this->delivery($knowledge['delivery_ka'] ?? null, 'ka'),
            'delivery_en' => $this->delivery($knowledge['delivery_en'] ?? null, 'en'),
            'facts' => is_string($knowledge['facts'] ?? null) ? $knowledge['facts'] : '',
            'topics' => $this->topics($knowledge['topics'] ?? null),
            'examples' => $this->examples($knowledge['examples'] ?? null),
        ];
    }

    /**
     * @return array{delivery: string, facts: string, topics?: array<string, string>, examples?: list<array{question: string, answer: string}>}
     */
    public function forAssistant(string $locale, ?string $question = null): array
    {
        $knowledge = $this->snapshot();

        $result = [
            'delivery' => $knowledge[$locale === 'ka' ? 'delivery_ka' : 'delivery_en'],
            'facts' => $knowledge['facts'],
        ];

        if ($question !== null) {
            $result['topics'] = $knowledge['topics'];
            $result['examples'] = $this->matchingExamples($question, $knowledge['examples']);
        }

        return $result;
    }

    public function save(string $deliveryKa, string $deliveryEn, ?string $facts, ?array $topics = null, ?array $examples = null): void
    {
        $previous = $topics === null || $examples === null ? $this->snapshot() : [];
        $data = [
            'delivery_ka' => trim($deliveryKa),
            'delivery_en' => trim($deliveryEn),
            'facts' => trim($facts ?? ''),
        ];
        $topics = $topics === null ? $previous['topics'] : $this->topics($topics);
        $examples = $examples === null ? $previous['examples'] : $this->examples($examples);
        if ($topics !== []) {
            $data['topics'] = $topics;
        }
        if ($examples !== []) {
            $data['examples'] = $examples;
        }
        $value = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        Setting::query()->upsert([['key' => self::SETTING_KEY, 'value' => $value]], ['key'], ['value']);
        Setting::forgetCache();
    }

    private function topics(mixed $stored): array
    {
        $topics = [];
        foreach (self::TOPICS as $key) {
            $note = $this->text(is_array($stored) ? ($stored[$key] ?? null) : null, self::TOPIC_LENGTH);
            if ($note !== '') {
                $topics[$key] = $note;
            }
        }

        return $topics;
    }

    private function examples(mixed $stored): array
    {
        $examples = [];
        foreach (array_slice(is_array($stored) ? $stored : [], 0, self::EXAMPLE_LIMIT) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $question = $this->text($entry['question'] ?? null, self::QUESTION_LENGTH);
            $answer = $this->text($entry['answer'] ?? null, self::ANSWER_LENGTH);
            if ($question !== '' && $answer !== '') {
                $examples[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return $examples;
    }

    private function matchingExamples(string $question, array $examples): array
    {
        $question = $this->normalize($question);
        if ($question === '') {
            return [];
        }

        $terms = $this->terms($question);
        $ranked = [];
        foreach ($examples as $index => $example) {
            $exampleQuestion = $this->normalize($example['question']);
            if ($exampleQuestion === '') {
                continue;
            }
            $overlap = count(array_intersect($terms, $this->terms($exampleQuestion)));
            $score = $question === $exampleQuestion ? 100 : $overlap;
            if ($score > 0) {
                $ranked[$index] = $score;
            }
        }

        arsort($ranked);

        return array_map(fn ($index) => $examples[$index], array_slice(array_keys($ranked), 0, 5));
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }

    private function terms(string $normalized): array
    {
        $stopWords = ['არის', 'იქნება', 'როგორ', 'რომელი', 'რამდენი', 'შეიძლება', 'მინდა', 'გაქვთ', 'შეგიძლიათ',
            'მაინტერესებს', 'გთხოვთ', 'ღირს', 'თქვენ', 'have', 'what', 'when', 'where', 'how', 'with', 'please', 'would'];
        $terms = array_filter(explode(' ', $normalized), fn (string $term) => mb_strlen($term) > 2 && ! in_array($term, $stopWords, true));

        return array_unique(array_map(fn (string $term) => preg_match('/^\p{L}+$/u', $term) && mb_strlen($term) > 5
            ? mb_substr($term, 0, 5) : $term, $terms));
    }

    private function text(mixed $value, int $limit): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $limit) : '';
    }

    private function delivery(mixed $value, string $locale): string
    {
        return is_string($value) && trim($value) !== '' ? $value : __('sales_chat_knowledge.default_delivery', [], $locale);
    }
}
