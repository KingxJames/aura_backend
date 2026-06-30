<?php

namespace Tests\Unit;

use App\Services\GeminiQuestionGeneratorService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GeminiQuestionGeneratorServiceTest extends TestCase
{
    private function geminiTextResponse(array $payload): array
    {
        return [
            'candidates' => [
                ['content' => ['parts' => [['text' => json_encode($payload)]]]],
            ],
        ];
    }

    public function test_generate_returns_a_valid_question_on_first_try(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiTextResponse([
                'question' => 'What note is on the bottom line of the treble staff? 🎼',
                'options' => ['E', 'G', 'F', 'D'],
                'ground_truth' => 'E',
                'hint' => 'Every Good Boy...',
                'explanation' => 'It spells EGBDF from the bottom up.',
            ])),
        ]);

        $question = (new GeminiQuestionGeneratorService())->generate('pitch', 1);

        $this->assertEquals('E', $question['ground_truth']);
        $this->assertCount(4, $question['options']);
        $this->assertContains($question['ground_truth'], $question['options']);
    }

    public function test_generate_retries_once_when_first_response_is_invalid(): void
    {
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                // ground_truth not present in options - invalid
                return Http::response($this->geminiTextResponse([
                    'question' => 'Bad question',
                    'options' => ['A', 'B', 'C', 'D'],
                    'ground_truth' => 'NOT_AN_OPTION',
                    'hint' => 'h',
                    'explanation' => 'e',
                ]));
            }

            return Http::response($this->geminiTextResponse([
                'question' => 'Good question',
                'options' => ['A', 'B', 'C', 'D'],
                'ground_truth' => 'B',
                'hint' => 'h',
                'explanation' => 'e',
            ]));
        });

        $question = (new GeminiQuestionGeneratorService())->generate('rhythm', 2);

        $this->assertEquals('Good question', $question['question']);
        $this->assertEquals(2, $callCount);
    }

    public function test_generate_throws_after_exhausting_retries_on_persistent_invalid_output(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiTextResponse([
                'question' => 'Bad question',
                'options' => ['A', 'B', 'C'], // only 3 options - invalid
                'ground_truth' => 'A',
                'hint' => 'h',
                'explanation' => 'e',
            ])),
        ]);

        $this->expectException(RuntimeException::class);

        (new GeminiQuestionGeneratorService())->generate('scales', 3);
    }

    public function test_generate_throws_on_http_failure(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('server error', 500),
        ]);

        $this->expectException(RuntimeException::class);

        (new GeminiQuestionGeneratorService())->generate('time_signatures', 1);
    }
}
