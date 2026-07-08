<?php

namespace Database\Seeders;

use App\Models\OpusLevel;
use Illuminate\Database\Seeder;

class OpusLevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            [
                'level_number' => 1,
                'title' => 'Opus 1',
                'description' => 'Foundations - matching a single steady tone within a comfortable middle range.',
                'target_notes' => ['C4', 'D4', 'E4', 'F4', 'G4'],
                'tolerance_cents' => 50,
            ],
            [
                'level_number' => 2,
                'title' => 'Opus 2',
                'description' => 'A full octave - extending accuracy across the C major scale.',
                'target_notes' => ['C4', 'D4', 'E4', 'F4', 'G4', 'A4', 'B4', 'C5'],
                'tolerance_cents' => 40,
            ],
            [
                'level_number' => 3,
                'title' => 'Opus 3',
                'description' => 'Wider range and chromatic colour - introducing flattened tones.',
                'target_notes' => ['Bb3', 'C4', 'D4', 'Eb4', 'F4', 'G4', 'A4', 'Bb4', 'C5', 'D5', 'Eb5', 'F5'],
                'tolerance_cents' => 35,
            ],
            [
                'level_number' => 4,
                'title' => 'Opus 4',
                'description' => 'Two-octave command - sharpened intonation across a wide vocal range.',
                'target_notes' => ['G3', 'A3', 'B3', 'C4', 'D4', 'E4', 'F#4', 'G4', 'A4', 'B4', 'C5', 'D5', 'E5', 'F#5', 'G5'],
                'tolerance_cents' => 25,
            ],
            [
                'level_number' => 5,
                'title' => 'Opus 5',
                'description' => 'Conservatory standard - full range, precise intonation across every tested tone.',
                'target_notes' => ['C3', 'D3', 'E3', 'F3', 'G3', 'A3', 'B3', 'C4', 'D4', 'E4', 'F4', 'G4', 'A4', 'B4', 'C5', 'D5', 'E5', 'F5', 'G5', 'A5'],
                'tolerance_cents' => 20,
            ],
        ];

        foreach ($levels as $level) {
            OpusLevel::firstOrCreate(
                ['level_number' => $level['level_number']],
                $level
            );
        }

        $this->command->info('Successfully seeded the Opus Syllabus levels!');
    }
}
