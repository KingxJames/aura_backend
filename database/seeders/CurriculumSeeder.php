<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Grade;
use App\Models\Quiz;

class CurriculumSeeder extends Seeder
{
    public function run(): void
    {
        // ==========================================
        // GRADE 1: FOUNDATION STAGE
        // ==========================================
        $grade1 = Grade::create([
            'id' => '11111111-1111-1111-1111-111111111111',
            'title' => 'Grade 1 Theory Foundation',
            'level_number' => 1,
            'description' => 'Introduction to basic music notation, clefs, and fundamental rhythmic math values.',
            'syllabus_focus' => 'Clef reading, note naming, and foundational rhythm arithmetic in common time signatures.'
        ]);

        Quiz::create([
            'id' => '99999999-1111-1111-1111-111111111111',
            'grade_id' => $grade1->id,
            'title' => 'Treble Clef Pitch Recognition',
            'description' => 'Identify absolute pitches sitting cleanly on the lines and spaces within the treble staff.',
            'content_jsonb' => [
                // --- Standard Pool ---
                [
                    'question_id' => 'g1_q1',
                    'difficulty' => 'standard',
                    'type' => 'multiple_choice',
                    'prompt' => 'Identify the pitch note residing on the second line from the bottom of a Treble Clef staff.',
                    'options' => ['E', 'G', 'B', 'D'],
                    'correct_answer' => 'G'
                ],
                [
                    'question_id' => 'g1_q2',
                    'difficulty' => 'standard',
                    'type' => 'true_false',
                    'prompt' => 'The note Middle C sits directly on a short custom ledger line underneath the treble staff.',
                    'options' => ['True', 'False'],
                    'correct_answer' => 'True'
                ],
                [
                    'question_id' => 'g1_q3',
                    'difficulty' => 'standard',
                    'type' => 'multiple_choice',
                    'prompt' => 'What note sits directly in the space between the third and fourth lines from the bottom of the treble staff?',
                    'options' => ['F', 'A', 'C', 'E'],
                    'correct_answer' => 'C'
                ],
                // --- Advanced Pool (Unlocked at 3x Streak) ---
                [
                    'question_id' => 'g1_adv_mtb1',
                    'difficulty' => 'advanced',
                    'type' => 'multiple_choice',
                    'prompt' => 'In functional harmony, which scale degree must be raised by a half-step to construct the leading tone in an A harmonic minor scale?',
                    'options' => ['F', 'G', 'C', 'D'],
                    'correct_answer' => 'G'
                ],
                [
                    'question_id' => 'g1_adv_mtb2',
                    'difficulty' => 'advanced',
                    'type' => 'multiple_choice',
                    'prompt' => 'What is the primary structural resolution target of an augmented sixth interval within classical functional harmony?',
                    'options' => ['Resolution outward by half-step to an octave', 'Resolution inward by whole-step to a perfect fifth', 'Parallel movement to a perfect fourth', 'Holding static tone configuration'],
                    'correct_answer' => 'Resolution outward by half-step to an octave'
                ]
            ]
        ]);

        Quiz::create([
            'id' => '99999999-1111-1111-1111-222222222222',
            'grade_id' => $grade1->id,
            'title' => 'Time Signatures & Rhythmic Beats',
            'description' => 'Master basic measure math configurations and note structural durations.',
            'content_jsonb' => [
                // --- Standard Pool ---
                [
                    'question_id' => 'g1_r1',
                    'difficulty' => 'standard',
                    'type' => 'multiple_choice',
                    'prompt' => 'How many quarter notes (crotchets) fit perfectly into a single standard 4/4 time signature measure?',
                    'options' => ['2', '3', '4', '6'],
                    'correct_answer' => '4'
                ],
                [
                    'question_id' => 'g1_r2',
                    'difficulty' => 'standard',
                    'type' => 'multiple_choice',
                    'prompt' => 'In a 3/4 time signature, what type of note or rest fills an entire measure completely?',
                    'options' => ['Dotted half note', 'Whole note', 'Half note', 'Quarter note'],
                    'correct_answer' => 'Dotted half note'
                ],
                // --- Advanced Pool (Unlocked at 3x Streak) ---
                [
                    'question_id' => 'g1_r_adv_mtb1',
                    'difficulty' => 'advanced',
                    'type' => 'multiple_choice',
                    'prompt' => 'Which of the following describes a compound duple time signature where the beat is subdivided into groups of three eighth notes?',
                    'options' => ['2/4', '6/8', '3/4', '9/8'],
                    'correct_answer' => '6/8'
                ]
            ]
        ]);

        // ==========================================
        // GRADE 2: EXPANDED SCALES & KEY SIGNATURES
        // ==========================================
        $grade2 = Grade::create([
            'id' => '22222222-2222-2222-2222-222222222222',
            'title' => 'Grade 2 Notation Progression',
            'level_number' => 2,
            'description' => 'Exploring complex key signatures, triplets, and basic minor scale structures.',
            'syllabus_focus' => 'Major/minor key literacy, accidental behavior, and intermediate rhythmic subdivisions.'
        ]);

        Quiz::create([
            'id' => '99999999-2222-2222-2222-111111111111',
            'grade_id' => $grade2->id,
            'title' => 'Major Key Signatures Explorer',
            'description' => 'Test your operational knowledge of sharps and flats up to three accidentals.',
            'content_jsonb' => [
                // --- Standard Pool ---
                [
                    'question_id' => 'g2_k1',
                    'difficulty' => 'standard',
                    'type' => 'multiple_choice',
                    'prompt' => 'Which major key signature contains exactly two sharps (F# and C#)?',
                    'options' => ['G Major', 'C Major', 'D Major', 'F Major'],
                    'correct_answer' => 'D Major'
                ],
                [
                    'question_id' => 'g2_k2',
                    'difficulty' => 'standard',
                    'type' => 'multiple_choice',
                    'prompt' => 'What is the correct order of sharps as they appear from left to right in a standard musical key signature?',
                    'options' => ['F-C-G-D-A-E-B', 'B-E-A-D-G-C-F', 'C-G-D-A-E-B-F', 'F-G-C-D-A-E-B'],
                    'correct_answer' => 'F-C-G-D-A-E-B'
                ],
                // --- Advanced Pool (Unlocked at 3x Streak) ---
                [
                    'question_id' => 'g2_k_adv_mtb1',
                    'difficulty' => 'advanced',
                    'type' => 'multiple_choice',
                    'prompt' => 'Based on symbolic harmonic relationships, what is the relative minor key signature of E Major?',
                    'options' => ['C# minor', 'F# minor', 'G# minor', 'B minor'],
                    'correct_answer' => 'C# minor'
                ]
            ]
        ]);
    }
}