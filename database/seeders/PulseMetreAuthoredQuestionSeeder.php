<?php

namespace Database\Seeders;

use App\Models\Grade;
use App\Models\PulseMetreAuthoredQuestion;
use App\Services\AuralExerciseGeneratorService;
use Illuminate\Database\Seeder;

/**
 * Loads Pulse & Metre's fully hand-authored 10-question bank from a JSON
 * dataset, mirroring GradeOneQuizSeeder's read-JSON-file-and-upsert pattern.
 *
 * Only METADATA is loaded here - the actual audio files must be placed by
 * hand under storage/app/public/audio/pulse_metre/ (same folder
 * PulseMetreClip uses). Each entry in
 * storage/app/datasets/pulse_metre_questions.json looks like:
 *   {
 *     "question_number": 4,
 *     "phase": "downbeat_tap",
 *     "filename": "q4_waltz.mp3",
 *     "time_signature": "3/4",
 *     "beat_timestamps_ms": [500, 1300, 2100, 2900, 3700, 4500]
 *   }
 * "phase" is authoring metadata only (not persisted) - it's validated here
 * against question_number so a typo doesn't silently ship the wrong content.
 * A partial bank (fewer than 10 entries, or none at all) is expected while
 * this is being built out - AuralModuleController falls back to the real
 * clip catalog / procedural generation for any unauthored question_number.
 */
class PulseMetreAuthoredQuestionSeeder extends Seeder
{
    public function run(): void
    {
        $grade = Grade::where('level_number', 1)->first();

        if (!$grade) {
            $this->command->error('Grade 1 does not exist yet - run GradeOneQuizSeeder first.');
            return;
        }

        $absolutePath = storage_path('app/datasets/pulse_metre_questions.json');

        if (!file_exists($absolutePath)) {
            $this->command->error("Could not find the JSON file at: {$absolutePath}");
            return;
        }

        $jsonString = file_get_contents($absolutePath);
        $questions = json_decode($jsonString, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->command->error("Invalid JSON format detected: " . json_last_error_msg());
            return;
        }

        foreach ($questions as $question) {
            $questionNumber = $question['question_number'];
            $expectedPhase = AuralExerciseGeneratorService::phaseForQuestionNumber($questionNumber);

            if (isset($question['phase']) && $question['phase'] !== $expectedPhase) {
                $this->command->error(
                    "Question {$questionNumber} declares phase '{$question['phase']}' but question_number "
                    . "{$questionNumber} maps to '{$expectedPhase}' - fix the mismatch before seeding."
                );
                continue;
            }

            if ($expectedPhase !== 'listen_mcq' && empty($question['beat_timestamps_ms'])) {
                $this->command->error(
                    "Question {$questionNumber} ({$expectedPhase}) is missing beat_timestamps_ms - required for every phase except listen_mcq."
                );
                continue;
            }

            PulseMetreAuthoredQuestion::updateOrCreate(
                ['grade_id' => $grade->id, 'question_number' => $questionNumber],
                [
                    'payload_jsonb' => [
                        'filename' => $question['filename'],
                        'time_signature' => $question['time_signature'],
                        'beat_timestamps_ms' => $question['beat_timestamps_ms'] ?? null,
                    ],
                ]
            );
        }

        $this->command->info('Successfully seeded Pulse & Metre authored questions into the database!');
    }
}
