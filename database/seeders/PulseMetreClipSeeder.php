<?php

namespace Database\Seeders;

use App\Models\PulseMetreClip;
use Illuminate\Database\Seeder;

/**
 * Loads the Pulse & Metre "listen_mcq" clip catalog from a JSON dataset,
 * mirroring GradeOneQuizSeeder's read-JSON-file-and-upsert pattern.
 *
 * This only loads METADATA - the actual audio files must be placed by hand
 * under storage/app/public/audio/pulse_metre/ (the 'public' disk, already
 * symlinked - see PulseMetreClip::audioUrl()). Each entry in
 * storage/app/datasets/pulse_metre_clips.json looks like:
 *   {
 *     "filename": "march_01.mp3",
 *     "time_signature": "2/4",
 *     "label": "March in C",
 *     "source": "Musopen",
 *     "license": "Public Domain",
 *     "attribution": null
 *   }
 */
class PulseMetreClipSeeder extends Seeder
{
    public function run(): void
    {
        $absolutePath = storage_path('app/datasets/pulse_metre_clips.json');

        if (!file_exists($absolutePath)) {
            $this->command->error("Could not find the JSON file at: {$absolutePath}");
            return;
        }

        $jsonString = file_get_contents($absolutePath);
        $clips = json_decode($jsonString, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->command->error("Invalid JSON format detected: " . json_last_error_msg());
            return;
        }

        foreach ($clips as $clip) {
            PulseMetreClip::updateOrCreate(
                ['filename' => $clip['filename']],
                [
                    'time_signature' => $clip['time_signature'],
                    'label' => $clip['label'] ?? null,
                    'source' => $clip['source'] ?? null,
                    'license' => $clip['license'] ?? null,
                    'attribution' => $clip['attribution'] ?? null,
                ]
            );
        }

        $this->command->info('Successfully seeded Pulse & Metre listening clips into the database!');
    }
}
