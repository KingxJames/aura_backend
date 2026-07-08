<?php

namespace Database\Seeders;

use App\Models\Grade;
use App\Models\Quiz;
use Illuminate\Database\Seeder;

class GradeFourQuizSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure Grade 4 exists in the database with its required Title and Description
        $grade = Grade::firstOrCreate(
            ['level_number' => 4],
            [
                'title' => 'Grade 4 Theory',
                'description' => 'Explores chord inversions, ornamentation, modulation between keys, and more complex rhythms.',
                'syllabus_focus' => 'Triad inversions and figured bass, ornaments (trill, mordent, turn, appoggiatura), modulation to closely related keys, and irregular time signatures.'
            ]
        );

        // 2. Read your custom JSON file using an absolute disk path
        $absolutePath = storage_path('app/datasets/custom_grade4_theory.json');

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
                'title' => 'Grade 4 Music Theory Complete Question Bank',
            ],
            [
                'description' => 'Comprehensive question bank covering all core syllabus topics for Grade 4 Music Theory.',
                'content_jsonb' => $questionsArray,
            ]
        );

        $this->command->info("Successfully seeded Grade 4 questions into the database!");
    }
}
