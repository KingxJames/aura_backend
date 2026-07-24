<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\PulseMetreAuthoredQuestion;
use App\Models\PulseMetreClip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PulseMetreSessionTest extends TestCase
{
    use RefreshDatabase;

    private function makeGrade(): Grade
    {
        return Grade::create([
            'title' => 'Test Grade',
            'level_number' => 1,
            'description' => 'Test grade for pulse metre session specs.',
            'syllabus_focus' => 'Test',
        ]);
    }

    /**
     * listen_mcq (Q1-3) picks a real clip from the catalog rather than
     * synthesizing one, so at least one row per time signature must exist or
     * generateExercise throws a 422 ("no clips seeded").
     */
    private function seedPulseMetreClips(): void
    {
        PulseMetreClip::create([
            'filename' => 'test-march.mp3',
            'time_signature' => '2/4',
            'label' => 'Test March',
        ]);
        PulseMetreClip::create([
            'filename' => 'test-waltz.mp3',
            'time_signature' => '3/4',
            'label' => 'Test Waltz',
        ]);
    }

    private function generate(int $gradeId): array
    {
        return $this->getJson("/api/v1/aural/modules/pulse_metre/exercise?grade_id={$gradeId}")
            ->assertOk()->json()['data'];
    }

    /**
     * Builds a correct submission body for whichever phase the exercise is in,
     * using the exercise's own (intentionally unhidden - see AuralExercise migration
     * notes) ground_truth/beat data, so every attempt in this test is a correct one.
     */
    private function correctAttemptBody(array $exercise): array
    {
        return match ($exercise['phase']) {
            'listen_mcq' => [
                'selected_time_signature' => $exercise['ground_truth']['time_signature'],
            ],
            'downbeat_tap' => [
                'tap_timestamps_ms' => array_map(
                    fn ($i) => $exercise['beat_timestamps_ms'][$i],
                    $exercise['downbeat_indices']
                ),
            ],
            'muted_bar_tap' => [
                'tap_timestamps_ms' => $exercise['beat_timestamps_ms'],
            ],
            'boss' => [
                'tap_timestamps_ms' => $exercise['beat_timestamps_ms'],
                'selected_time_signature' => $exercise['ground_truth']['time_signature'],
            ],
        };
    }

    private function submit(int $exerciseId, array $body): array
    {
        return $this->postJson("/api/v1/aural/modules/exercises/{$exerciseId}/attempt", $body)
            ->assertOk()->json();
    }

    public function test_full_ten_question_arc_progresses_through_all_four_phases(): void
    {
        $user = User::factory()->create();
        $grade = $this->makeGrade();
        $this->seedPulseMetreClips();
        Sanctum::actingAs($user);

        $expectedPhases = [
            1 => 'listen_mcq', 2 => 'listen_mcq', 3 => 'listen_mcq',
            4 => 'downbeat_tap', 5 => 'downbeat_tap', 6 => 'downbeat_tap',
            7 => 'muted_bar_tap', 8 => 'muted_bar_tap', 9 => 'muted_bar_tap',
            10 => 'boss',
        ];

        foreach ($expectedPhases as $questionNumber => $expectedPhase) {
            $exercise = $this->generate($grade->id);

            $this->assertEquals($expectedPhase, $exercise['phase']);
            $this->assertEquals($questionNumber, $exercise['session_progress']['question_number']);

            if ($expectedPhase === 'listen_mcq') {
                $this->assertArrayHasKey('question', $exercise);
                $this->assertArrayHasKey('audio_url', $exercise);
                $this->assertArrayHasKey('clip_label', $exercise);
                $this->assertArrayNotHasKey('downbeat_indices', $exercise);
                $this->assertArrayNotHasKey('beat_timestamps_ms', $exercise);
            } elseif ($expectedPhase === 'downbeat_tap') {
                $this->assertArrayHasKey('downbeat_indices', $exercise);
                $this->assertArrayNotHasKey('question', $exercise);
            } elseif ($expectedPhase === 'muted_bar_tap') {
                $this->assertArrayHasKey('audible_beat_indices', $exercise);
                $this->assertArrayNotHasKey('question', $exercise);
                $this->assertCount($exercise['beats_per_bar'], $exercise['audible_beat_indices']);
            } else { // boss
                $this->assertArrayHasKey('audible_beat_indices', $exercise);
                $this->assertArrayHasKey('question', $exercise);
            }

            $attempt = $this->submit($exercise['exercise_id'], $this->correctAttemptBody($exercise));

            $this->assertTrue($attempt['is_correct']);
            $this->assertEquals($questionNumber, $attempt['session_progress']['question_number']);
            $this->assertEquals($expectedPhase, $attempt['session_progress']['phase']);
            $this->assertEquals($questionNumber, $attempt['session_progress']['correct_count']);

            $expectedStatus = $questionNumber === 10 ? 'completed' : 'active';
            $this->assertEquals($expectedStatus, $attempt['session_progress']['status']);
        }

        // The next generate call should start a brand new session back at question 1.
        $nextArc = $this->generate($grade->id);
        $this->assertEquals(1, $nextArc['session_progress']['question_number']);
        $this->assertEquals('listen_mcq', $nextArc['phase']);
    }

    public function test_downbeat_phase_rejects_spammed_taps(): void
    {
        $user = User::factory()->create();
        $grade = $this->makeGrade();
        $this->seedPulseMetreClips();
        Sanctum::actingAs($user);

        // Burn through phase 1 (Q1-3) with correct answers to reach downbeat_tap (Q4).
        for ($i = 0; $i < 3; $i++) {
            $exercise = $this->generate($grade->id);
            $this->submit($exercise['exercise_id'], $this->correctAttemptBody($exercise));
        }

        $exercise = $this->generate($grade->id);
        $this->assertEquals('downbeat_tap', $exercise['phase']);

        // Tap every single beat (not just downbeats) - should fail the spam guard.
        $attempt = $this->submit($exercise['exercise_id'], [
            'tap_timestamps_ms' => $exercise['beat_timestamps_ms'],
        ]);

        $this->assertFalse($attempt['is_correct']);
    }

    public function test_authored_questions_are_served_verbatim_ahead_of_the_fallback(): void
    {
        $user = User::factory()->create();
        $grade = $this->makeGrade();
        $this->seedPulseMetreClips();
        Sanctum::actingAs($user);

        // Author Q1 (listen_mcq) and Q4 (downbeat_tap) only - Q2/Q3/Q5/Q6 stay
        // unauthored, so the fallback (clip catalog / procedural generation)
        // must still cover them.
        PulseMetreAuthoredQuestion::create([
            'grade_id' => $grade->id,
            'question_number' => 1,
            'payload_jsonb' => [
                'filename' => 'my-march.mp3',
                'time_signature' => '2/4',
            ],
        ]);
        PulseMetreAuthoredQuestion::create([
            'grade_id' => $grade->id,
            'question_number' => 4,
            'payload_jsonb' => [
                'filename' => 'my-waltz.mp3',
                'time_signature' => '3/4',
                'beat_timestamps_ms' => [100, 900, 1700, 2500, 3300, 4100],
            ],
        ]);

        // Q1: authored listen_mcq is served verbatim, not a random clip.
        $q1 = $this->generate($grade->id);
        $this->assertEquals('listen_mcq', $q1['phase']);
        $this->assertEquals(Storage::url('audio/pulse_metre/my-march.mp3'), $q1['audio_url']);
        $this->assertEquals('2/4', $q1['ground_truth']['time_signature']);
        $this->submit($q1['exercise_id'], $this->correctAttemptBody($q1));

        // Q2, Q3: unauthored - fall back to the clip catalog seeded above.
        for ($i = 0; $i < 2; $i++) {
            $exercise = $this->generate($grade->id);
            $this->assertArrayHasKey('clip_label', $exercise);
            $this->submit($exercise['exercise_id'], $this->correctAttemptBody($exercise));
        }

        // Q4: authored downbeat_tap is served verbatim - exact beat timestamps,
        // no random tempo/time-signature roll.
        $q4 = $this->generate($grade->id);
        $this->assertEquals('downbeat_tap', $q4['phase']);
        $this->assertEquals(Storage::url('audio/pulse_metre/my-waltz.mp3'), $q4['audio_url']);
        $this->assertEquals('3/4', $q4['time_signature']);
        $this->assertEquals([100, 900, 1700, 2500, 3300, 4100], $q4['beat_timestamps_ms']);
        $this->assertEquals([0, 3], $q4['downbeat_indices']);

        $attempt = $this->submit($q4['exercise_id'], $this->correctAttemptBody($q4));
        $this->assertTrue($attempt['is_correct']);
    }

    public function test_listen_phase_returns_422_when_no_clips_are_seeded(): void
    {
        $user = User::factory()->create();
        $grade = $this->makeGrade();
        Sanctum::actingAs($user);

        // Empty catalog (no seedPulseMetreClips() call) - Q1 always rolls
        // listen_mcq, which should fail clearly rather than silently falling
        // back to a synthesized click track.
        $this->getJson("/api/v1/aural/modules/pulse_metre/exercise?grade_id={$grade->id}")
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }
}
