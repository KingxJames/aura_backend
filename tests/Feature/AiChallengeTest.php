<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Quiz;
use App\Models\QuizSessions;
use App\Models\TopicMastery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiChallengeTest extends TestCase
{
    use RefreshDatabase;

    private const TOPICS = ['pitch', 'rhythm', 'scales', 'time_signatures'];

    private function makeQuiz(): Quiz
    {
        $grade = Grade::create([
            'title' => 'Test Grade',
            'level_number' => 1,
            'description' => 'Test',
            'syllabus_focus' => 'Test',
        ]);

        $id = 1;
        $questions = [];
        foreach (self::TOPICS as $topic) {
            foreach ([1, 2, 3] as $difficulty) {
                $questions[] = [
                    'id' => $id++,
                    'question' => "Question about {$topic} at difficulty {$difficulty}",
                    'options' => ['A', 'B', 'C', 'D'],
                    'ground_truth' => 'A',
                    'hint' => 'hint text',
                    'explanation' => 'explanation text',
                    'metadata' => ['topic' => $topic, 'difficulty' => $difficulty, 'image_url' => null],
                ];
            }
        }

        return Quiz::create([
            'grade_id' => $grade->id,
            'title' => 'Test Quiz',
            'description' => 'Test',
            'content_jsonb' => $questions,
        ]);
    }

    private function fakeGeminiQuestion(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode([
                        'question' => 'AI generated question about rhythm? 🥁',
                        'options' => ['Quaver', 'Crotchet', 'Minim', 'Semibreve'],
                        'ground_truth' => 'Crotchet',
                        'hint' => 'It is worth one beat.',
                        'explanation' => 'A crotchet is the standard one-beat note.',
                    ])]]],
                ]],
            ]),
        ]);
    }

    private function beginQuizSession(User $user, Quiz $quiz): array
    {
        Sanctum::actingAs($user);
        return $this->postJson('/api/quiz/session/start', ['quiz_id' => $quiz->id])
            ->assertCreated()->json();
    }

    public function test_ai_challenge_targets_the_weakest_topic_and_does_not_leak_the_answer(): void
    {
        $user = User::factory()->create(['elo_rating' => 1000]);
        $quiz = $this->makeQuiz();

        TopicMastery::insert([
            ['user_id' => $user->id, 'topic' => 'pitch', 'mastery' => 0.90, 'attempts' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'topic' => 'rhythm', 'mastery' => 0.10, 'attempts' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'topic' => 'scales', 'mastery' => 0.80, 'attempts' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'topic' => 'time_signatures', 'mastery' => 0.70, 'attempts' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $start = $this->beginQuizSession($user, $quiz);
        $this->fakeGeminiQuestion();

        $response = $this->postJson('/api/quiz/session/ai-challenge', [
            'session_id' => $start['session_id'],
        ])->assertCreated()->json();

        $this->assertEquals('rhythm', $response['topic']);
        $this->assertEquals('ai_generated', $response['source']);
        $this->assertArrayNotHasKey('ground_truth', $response['question']);
        $this->assertArrayNotHasKey('hint', $response['question']);
        $this->assertArrayNotHasKey('explanation', $response['question']);
        $this->assertEquals(['Quaver', 'Crotchet', 'Minim', 'Semibreve'], $response['question']['options']);

        $this->assertNotNull(QuizSessions::find($start['session_id'])->pending_ai_question);
    }

    public function test_ai_challenge_answer_correct_updates_mastery_and_clears_pending(): void
    {
        $user = User::factory()->create(['elo_rating' => 1000]);
        $quiz = $this->makeQuiz();

        TopicMastery::insert([
            ['user_id' => $user->id, 'topic' => 'pitch', 'mastery' => 0.90, 'attempts' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'topic' => 'rhythm', 'mastery' => 0.10, 'attempts' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'topic' => 'scales', 'mastery' => 0.80, 'attempts' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'topic' => 'time_signatures', 'mastery' => 0.70, 'attempts' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $start = $this->beginQuizSession($user, $quiz);
        $this->fakeGeminiQuestion();
        $this->postJson('/api/quiz/session/ai-challenge', ['session_id' => $start['session_id']])->assertCreated();

        $response = $this->postJson('/api/quiz/session/ai-challenge/answer', [
            'session_id' => $start['session_id'],
            'selected_option' => 'Crotchet',
        ])->assertOk()->json();

        $this->assertTrue($response['is_correct']);
        $this->assertEquals('Crotchet', $response['correct_answer']);
        $this->assertEquals('rhythm', $response['topic']);
        $this->assertGreaterThan(0.30, $response['topic_mastery']['rhythm']);

        $this->assertNull(QuizSessions::find($start['session_id'])->pending_ai_question);
    }

    public function test_ai_challenge_answer_incorrect_still_reports_explanation_and_clears_pending(): void
    {
        $user = User::factory()->create(['elo_rating' => 1000]);
        $quiz = $this->makeQuiz();

        $start = $this->beginQuizSession($user, $quiz);
        $this->fakeGeminiQuestion();
        $this->postJson('/api/quiz/session/ai-challenge', ['session_id' => $start['session_id']])->assertCreated();

        $response = $this->postJson('/api/quiz/session/ai-challenge/answer', [
            'session_id' => $start['session_id'],
            'selected_option' => 'Quaver',
        ])->assertOk()->json();

        $this->assertFalse($response['is_correct']);
        $this->assertEquals('Crotchet', $response['correct_answer']);
        $this->assertNotNull($response['hint']);
        $this->assertNotNull($response['explanation']);

        $this->assertNull(QuizSessions::find($start['session_id'])->pending_ai_question);
    }

    public function test_ai_challenge_answer_without_a_pending_question_returns_404(): void
    {
        $user = User::factory()->create(['elo_rating' => 1000]);
        $quiz = $this->makeQuiz();
        $start = $this->beginQuizSession($user, $quiz);

        $this->postJson('/api/quiz/session/ai-challenge/answer', [
            'session_id' => $start['session_id'],
            'selected_option' => 'Anything',
        ])->assertNotFound();
    }

    public function test_ai_challenge_returns_502_when_generation_fails(): void
    {
        $user = User::factory()->create(['elo_rating' => 1000]);
        $quiz = $this->makeQuiz();
        $start = $this->beginQuizSession($user, $quiz);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('upstream error', 500),
        ]);

        $this->postJson('/api/quiz/session/ai-challenge', [
            'session_id' => $start['session_id'],
        ])->assertStatus(502);
    }
}
