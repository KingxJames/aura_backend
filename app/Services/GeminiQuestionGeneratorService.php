<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Generates brand-new Grade 1 music theory practice questions on demand,
 * targeting a specific weak topic identified by KnowledgeTracingService,
 * instead of only ever pulling from the static 100-question bank.
 */
class GeminiQuestionGeneratorService
{
    private const DIFFICULTY_LABELS = [
        1 => 'easy / introductory',
        2 => 'medium',
        3 => 'challenging / advanced for this grade',
    ];

    private const RESPONSE_SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'question' => ['type' => 'STRING'],
            'options' => [
                'type' => 'ARRAY',
                'items' => ['type' => 'STRING'],
                'minItems' => 4,
                'maxItems' => 4,
            ],
            'ground_truth' => ['type' => 'STRING'],
            'hint' => ['type' => 'STRING'],
            'explanation' => ['type' => 'STRING'],
        ],
        'required' => ['question', 'options', 'ground_truth', 'hint', 'explanation'],
    ];

    public function generate(string $topic, int $difficulty, int $maxAttempts = 2): array
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $question = $this->requestOne($topic, $difficulty);
                $this->assertValid($question);
                return $question;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        throw new RuntimeException(
            "Failed to generate a valid AI practice question after {$maxAttempts} attempt(s): " . $lastError?->getMessage()
        );
    }

    private function requestOne(string $topic, int $difficulty): array
    {
        $apiKey = env('GEMINI_API_KEY');
        if (empty($apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY is not configured on the server.');
        }

        $difficultyLabel = self::DIFFICULTY_LABELS[$difficulty] ?? 'medium';
        $topicLabel = str_replace('_', ' ', $topic);

        $prompt = "Generate one brand-new ABRSM-style Grade 1 music theory multiple-choice question "
            . "on the topic of '{$topicLabel}', at {$difficultyLabel} difficulty. "
            . "Match the tone of a fun, encouraging children's music theory app: include one relevant emoji in the question text, "
            . "give exactly 4 plausible options where only one is correct, a short encouraging hint that doesn't give away the answer, "
            . "and a one or two sentence explanation of why the correct answer is right. "
            . "The 'ground_truth' value must be copied verbatim from one of the 'options' strings.";

        $response = Http::timeout(25)
            ->acceptJson()
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}", [
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => self::RESPONSE_SCHEMA,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Gemini request failed ({$response->status()}): {$response->body()}");
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        if (!$text) {
            throw new RuntimeException('Gemini returned no generated content.');
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Gemini response was not valid JSON.');
        }

        return $decoded;
    }

    private function assertValid(array $question): void
    {
        if (empty($question['question']) || !is_string($question['question'])) {
            throw new RuntimeException('Missing or invalid "question" field.');
        }

        $options = $question['options'] ?? null;
        if (!is_array($options) || count($options) !== 4 || count(array_unique($options)) !== 4) {
            throw new RuntimeException('"options" must contain exactly 4 unique strings.');
        }

        if (!in_array($question['ground_truth'] ?? null, $options, true)) {
            throw new RuntimeException('"ground_truth" does not match any of the "options".');
        }

        if (empty($question['hint']) || empty($question['explanation'])) {
            throw new RuntimeException('Missing "hint" or "explanation".');
        }
    }
}
