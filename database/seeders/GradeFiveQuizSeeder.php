<?php

namespace Database\Seeders;

use App\Models\Grade;
use App\Models\Quiz;
use Illuminate\Database\Seeder;

class GradeFiveQuizSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure Grade 5 exists in the database with its required Title and Description
        $grade = Grade::firstOrCreate(
            ['level_number' => 5],
            [
                'title' => 'Grade 5 Theory',
                'description' => 'The capstone grade — chromatic harmony, musical form, transposing instruments, and score analysis.',
                'syllabus_focus' => 'Chromatic chords and the diminished 7th, binary/ternary/rondo/variation form, transposing instruments, and reading tempo, dynamic, and articulation markings in a score.'
            ]
        );

        // 2. Read your custom JSON file using an absolute disk path
        $absolutePath = storage_path('app/datasets/custom_grade5_theory.json');

        if (!file_exists($absolutePath)) {
            $this->command->error("Could not find the JSON file at: {$absolutePath}");
            return;
        }

        $jsonString = file_get_contents($absolutePath);
        $questionsArray = json_decode($jsonString, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->command->error("Invalid JSON format detected: " . json_last_error_msg());
            return;
        }

        // 3. Save or update the Quiz record with your content_jsonb array
        Quiz::updateOrCreate(
            [
                'grade_id' => $grade->id,
                'title' => 'Grade 5 Music Theory Complete Question Bank',
            ],
            [
                'description' => 'Comprehensive question bank covering all core syllabus topics for Grade 5 Music Theory.',
                'content_jsonb' => $questionsArray,
            ]
        );

        $this->command->info("Successfully seeded Grade 5 questions into the database!");
    }
}
