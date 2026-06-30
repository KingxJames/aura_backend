<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Quiz;
use App\Models\TopicMastery;
use App\Models\User;
use App\Services\KnowledgeTracingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdaptiveQuizSessionTest extends TestCase
{
    use RefreshDatabase;

    private const TOPICS = ['pitch', 'rhythm', 'scales', 'time_signatures'];

    protected function setUp(): void
    {
        parent::setUp();

        // Wrong answers trigger a "related practice" lookup that embeds via Hugging
        // Face; stub it so these tests stay hermetic and don't hit the real network.
        Http::fake(function ($request) {
            $count = count($request->data()['inputs'] ?? [null]);
            return Http::response(array_fill(0, $count, array_fill(0, 384, 0.01)));
        });
    }

    /**
     * Self-contained fixture (4 topics x 3 difficulties = 12 questions) so these
     * tests don't depend on the real seeded dataset.
     */
    private function makeQuiz(): Quiz
    {
        $grade = Grade::create([
            'title' => 'Test Grade',
            'level_number' => 1,
            'description' => 'Test grade for adaptive engine specs.',
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
            'description' => 'Test quiz for adaptive engine specs.',
            'content_jsonb' => $questions,
        ]);
    }

    private function answerCorrectly(int $sessionId, array $question): array
    {
        return $this->postJson('/api/quiz/session/step', [
            'session_id' => $sessionId,
            'question_id' => $question['id'],
            'selected_option' => $question['ground_truth'],
        ])->assertOk()->json();
    }

    public function test_correct_and_incorrect_answers_move_the_students_elo_rating(): void
    {
        $user = User::factory()->create(['elo_rating' => 1000]);
        $quiz = $this->makeQuiz();
        Sanctum::actingAs($user);

        $start = $this->postJson('/api/quiz/session/start', ['quiz_id' => $quiz->id])
            ->assertCreated()->json();

        $this->assertEquals(1000.0, $start['student_rating']);

        $correctStep = $this->postJson('/api/quiz/session/step', [
            'session_id' => $start['session_id'],
            'question_id' => $start['question']['id'],
            'selected_option' => 'A', // every fixture question's ground truth
        ])->assertOk()->json();

        $this->assertTrue($correctStep['is_correct']);
        $this->assertGreaterThan(1000, $correctStep['student_rating']);

        $wrongStep = $this->postJson('/api/quiz/session/step', [
            'session_id' => $start['session_id'],
            'question_id' => $correctStep['next_question']['id'],
            'selected_option' => 'NOT_THE_GROUND_TRUTH',
        ])->assertOk()->json();

        $this->assertFalse($wrongStep['is_correct']);
        $this->assertLessThan($correctStep['student_rating'], $wrongStep['student_rating']);
    }

    public function test_session_fails_after_three_incorrect_answers(): void
    {
        $user = User::factory()->create(['elo_rating' => 1000]);
        $quiz = $this->makeQuiz();
        Sanctum::actingAs($user);

        $start = $this->postJson('/api/quiz/session/start', ['quiz_id' => $quiz->id])
            ->assertCreated()->json();

        $sessionId = $start['session_id'];
        $questionId = $start['question']['id'];
        $response = [];

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/quiz/session/step', [
                'session_id' => $sessionId,
                'question_id' => $questionId,
                'selected_option' => 'WRONG',
            ])->assertOk()->json();

            if ($response['status'] !== 'failed') {
                $questionId = $response['next_question']['id'];
            }
        }

        $this->assertEquals('failed', $response['status']);
        $this->assertEquals(0, $response['lives_remaining']);
    }

    public function test_no_question_repeats_within_a_session(): void
    {
        $user = User::factory()->create(['elo_rating' => 1000]);
        $quiz = $this->makeQuiz();
        Sanctum::actingAs($user);

        $start = $this->postJson('/api/quiz/session/start', ['quiz_id' => $quiz->id])
            ->assertCreated()->json();

        $seenIds = [$start['question']['id']];
        $questionId = $start['question']['id'];

        for ($i = 0; $i < 8; $i++) {
            $response = $this->postJson('/api/quiz/session/step', [
                'session_id' => $start['session_id'],
                'question_id' => $questionId,
                'selected_option' => 'A',
            ])->assertOk()->json();

            $nextId = $response['next_question']['id'];
            $this->assertNotContains($nextId, $seenIds, "Question {$nextId} was served twice in one session.");

            $seenIds[] = $nextId;
            $questionId = $nextId;
        }
    }

    public function test_difficulty_tier_escalates_as_rating_climbs(): void
    {
        $user = User::factory()->create(['elo_rating' => 1000]);
        $quiz = $this->makeQuiz();
        Sanctum::actingAs($user);

        $start = $this->postJson('/api/quiz/session/start', ['quiz_id' => $quiz->id])
            ->assertCreated()->json();

        $this->assertEquals(2, $start['difficulty_tier']);

        $questionId = $start['question']['id'];
        $response = [];

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/quiz/session/step', [
                'session_id' => $start['session_id'],
                'question_id' => $questionId,
                'selected_option' => 'A',
            ])->assertOk()->json();
            $questionId = $response['next_question']['id'];
        }

        $this->assertEquals(3, $response['difficulty_tier']);
    }

    public function test_topic_mastery_drops_when_a_topic_is_repeatedly_missed(): void
    {
        $user = User::factory()->create(['elo_rating' => 1000]);
        $quiz = $this->makeQuiz();

        $kt = $this->app->make(KnowledgeTracingService::class);
        $before = $kt->getMasteryMap($user->id, self::TOPICS)['rhythm'];

        $kt->recordAttempt($user->id, 'rhythm', false);
        $kt->recordAttempt($user->id, 'rhythm', false);
        $after = $kt->getMasteryMap($user->id, self::TOPICS)['rhythm'];

        $this->assertLessThan($before, $after);
    }

    public function test_weighted_topic_selection_favors_the_students_weakest_topic(): void
    {
        $user = User::factory()->create(['elo_rating' => 1000]);

        TopicMastery::insert([
            ['user_id' => $user->id, 'topic' => 'pitch', 'mastery' => 0.90, 'attempts' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'topic' => 'rhythm', 'mastery' => 0.10, 'attempts' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'topic' => 'scales', 'mastery' => 0.50, 'attempts' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'topic' => 'time_signatures', 'mastery' => 0.50, 'attempts' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $kt = $this->app->make(KnowledgeTracingService::class);
        $tally = array_fill_keys(self::TOPICS, 0);

        for ($i = 0; $i < 1000; $i++) {
            $tally[$kt->pickWeightedTopic($user->id, self::TOPICS)]++;
        }

        $this->assertGreaterThan($tally['pitch'], $tally['rhythm']);
        $this->assertGreaterThan($tally['scales'] * 0.5, $tally['rhythm'] * 0.5); // sanity: rhythm isn't starved out
    }

    public function test_finalize_reports_score_percentage_rating_and_topic_mastery(): void
    {
        $user = User::factory()->create(['elo_rating' => 1000]);
        $quiz = $this->makeQuiz();
        Sanctum::actingAs($user);

        $start = $this->postJson('/api/quiz/session/start', ['quiz_id' => $quiz->id])
            ->assertCreated()->json();

        $step = $this->postJson('/api/quiz/session/step', [
            'session_id' => $start['session_id'],
            'question_id' => $start['question']['id'],
            'selected_option' => 'A',
        ])->assertOk()->json();

        $final = $this->postJson('/api/quiz/session/finalize', [
            'session_id' => $start['session_id'],
        ])->assertOk()->json();

        $this->assertEquals('completed', $final['status']);
        $this->assertEquals(1, $final['total_answered']);
        $this->assertEquals(1, $final['total_correct']);
        $this->assertEquals(100.0, $final['final_score_percentage']);
        $this->assertEquals($step['student_rating'], $final['student_rating']);
        foreach (self::TOPICS as $topic) {
            $this->assertArrayHasKey($topic, $final['topic_mastery']);
        }
    }
}
