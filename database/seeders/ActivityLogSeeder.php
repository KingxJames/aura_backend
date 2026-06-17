<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Quiz;
use App\Models\UserProgress;
use App\Models\TutorConversation;
use App\Models\AuralAttempt;

class ActivityLogSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Grab the predictable test student
        $testStudent = User::where('email', 'student@aura.edu')->first();

        if (!$testStudent) {
            return;
        }

        // 2. Fetch the newly seeded quizzes dynamically to grab their fresh integer IDs
        $trebleQuiz = Quiz::where('title', 'Treble Clef Pitch Recognition')->first();
        $rhythmQuiz = Quiz::where('title', 'Time Signatures & Rhythmic Beats')->first();

        // 3. Seed historical quiz score performance metrics using the correct IDs
        if ($trebleQuiz) {
            UserProgress::create([
                'user_id' => $testStudent->id,
                'quiz_id' => $trebleQuiz->id, // Dynamic integer ID
                'score' => 90.00,
                'created_at' => now()->subDays(3)
            ]);
        }

        if ($rhythmQuiz) {
            UserProgress::create([
                'user_id' => $testStudent->id,
                'quiz_id' => $rhythmQuiz->id, // Dynamic integer ID
                'score' => 100.00,
                'created_at' => now()->subDays(1)
            ]);
        }

        // 4. Seed a short conversation history thread for the Tutor chat window UI
        TutorConversation::create([
            'user_id' => $testStudent->id,
            'message_type' => 'user',
            'content' => 'What is the purpose of an accidental in a musical piece?'
        ]);

        TutorConversation::create([
            'user_id' => $testStudent->id,
            'message_type' => 'ai',
            'content' => "An **accidental** is a musical symbol (like a sharp ♯, flat ♭, or natural ♮) that temporarily alters the pitch of a note by raising or lowering it by a half-step. It applies for the remainder of the measure!"
        ]);

        // 5. Seed an audio pitch check interaction for the Aural singing analytics engine
        AuralAttempt::create([
            'user_id' => $testStudent->id,
            'audio_path' => 'audio/vocal_tests/sample_attempt_01.wav',
            'target_note' => 'A4',
            'detected_frequency' => 442.50,
            'cents_deviation' => 9.8,
            'feedback_text' => 'Vocal steady. Beautiful tone control, stabilized beautifully near target standard frequency.'
        ]);
    }
}
