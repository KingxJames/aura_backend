<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * ALGORITHMIC AURAL EXERCISE GENERATOR
 * =====================================
 * One method per Aural module (1A-1D). Each method procedurally builds a fresh
 * exercise + its answer key, so every attempt is a new variation instead of
 * pulling from a fixed question bank (unlike the Theory quizzes).
 *
 * Only Grade 1's rules are implemented right now. Every method throws for any
 * other grade level rather than silently returning something wrong - Grades
 * 2-5 need their own ABRSM-style rules defined before we can generate for them.
 */
class AuralExerciseGeneratorService
{
    // The 12 chromatic pitch names, used as a lookup table when converting a
    // "scale degree" number into an actual note name (e.g. degree 2 in C major = "E").
    private const CHROMATIC_NOTES = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];

    // Semitone distance of each of the 7 major-scale degrees above the tonic
    // (the classic "whole-whole-half-whole-whole-whole-half" pattern).
    private const MAJOR_SCALE_SEMITONES = [0, 2, 4, 5, 7, 9, 11];

    // Grade 1 ABRSM-style constraint: only these four "easy" major keys are used.
    private const GRADE_1_KEYS = ['C', 'G', 'D', 'F'];

    /**
     * 1A: PULSE & METRE
     * Builds a short rhythmic loop (just beat timestamps - no pitches involved)
     * and asks the user to identify whether it's in 2-time or 3-time.
     */
    public function generatePulseMetre(int $gradeLevel): array
    {
        $this->assertGradeOneOnly($gradeLevel, 'Pulse & Metre');

        $tempoBpm = random_int(60, 100);
        $timeSignature = random_int(0, 1) === 0 ? '2/4' : '3/4';
        $beatsPerBar = $timeSignature === '2/4' ? 2 : 3;
        $bars = 2;
        $totalBeats = $beatsPerBar * $bars;

        // How many milliseconds separate each beat at this tempo.
        $beatIntervalMs = (int) round(60000 / $tempoBpm);

        // Small lead-in before the first beat so the user has time to get ready to tap.
        $leadInMs = 500;
        $beatTimestampsMs = [];
        for ($i = 0; $i < $totalBeats; $i++) {
            $beatTimestampsMs[] = $leadInMs + ($i * $beatIntervalMs);
        }

        return [
            'module_type' => 'pulse_metre',
            'tempo_bpm' => $tempoBpm,
            'time_signature' => $timeSignature,
            'beats_per_bar' => $beatsPerBar,
            'bars' => $bars,
            // The client plays a click/loop at these exact millisecond offsets and
            // captures the user's tap timestamps to compare against them later.
            'beat_timestamps_ms' => $beatTimestampsMs,
            'question' => [
                'prompt' => 'Was the music in 2 time or 3 time?',
                'options' => ['2/4', '3/4'],
            ],
            // Only used server-side by submitAttempt() to grade the follow-up MCQ.
            'ground_truth' => [
                'time_signature' => $timeSignature,
            ],
        ];
    }

    /**
     * 1B: ECHO SINGING
     * Generates the 2-bar melodic phrase the user must sing back. The DSP scoring
     * for this module isn't implemented yet (see AuralModuleController::submitAttempt),
     * but the exercise shape is defined now so the client has something to play.
     */
    public function generateEchoPhrase(int $gradeLevel): array
    {
        $this->assertGradeOneOnly($gradeLevel, 'Echo Singing');

        $key = self::GRADE_1_KEYS[array_rand(self::GRADE_1_KEYS)];

        // Grade 1 rule: 2 bars of 4/4, melody never leaps more than a 3rd
        // (i.e. never moves more than 2 scale degrees) between consecutive notes.
        $noteSequence = $this->generateStepwiseMelody($key, beatsBudget: 8.0, maxDegreeStep: 2);

        return [
            'module_type' => 'echo_singing',
            'key' => $key,
            'bars' => 2,
            'note_sequence' => $noteSequence,
            'ground_truth' => [
                // Kept for when real phrase-matching DSP is wired up later.
                'note_sequence' => $noteSequence,
            ],
        ];
    }

    /**
     * 1C: SPOTTING THE DIFFERENCE
     * Plays the same short melody twice, but the second playback has one note's
     * pitch altered near either the beginning or the end. User picks which.
     */
    public function generateSpotDifference(int $gradeLevel): array
    {
        $this->assertGradeOneOnly($gradeLevel, 'Spotting the Difference');

        $key = self::GRADE_1_KEYS[array_rand(self::GRADE_1_KEYS)];
        $originalSequence = $this->generateStepwiseMelody($key, beatsBudget: 4.0, maxDegreeStep: 2);
        $noteCount = count($originalSequence);

        // Split the sequence into thirds and pick the changed note from either the
        // first or last third, so "beginning" vs "end" is always unambiguous.
        $thirdSize = max(1, (int) floor($noteCount / 3));
        $changeNearBeginning = random_int(0, 1) === 0;

        if ($changeNearBeginning) {
            $changedIndex = random_int(0, $thirdSize - 1);
            $position = 'beginning';
        } else {
            $changedIndex = random_int($noteCount - $thirdSize, $noteCount - 1);
            $position = 'end';
        }

        $alteredSequence = $originalSequence;
        $originalNote = $originalSequence[$changedIndex];

        // Shift the chosen note by 1-2 scale degrees (up or down) so it's audibly
        // different but still diatonic - re-roll if it happens to land unchanged.
        do {
            $shift = random_int(1, 2) * (random_int(0, 1) === 0 ? -1 : 1);
            $alteredNote = $this->degreeToNote($key, $originalNote['degree'] + $shift, $originalNote['octave_offset']);
        } while ($alteredNote['note_name'] === $originalNote['note_name']);

        $alteredSequence[$changedIndex] = array_merge($alteredNote, ['duration_beats' => $originalNote['duration_beats']]);

        return [
            'module_type' => 'spot_difference',
            'key' => $key,
            'original_sequence' => $originalSequence,
            'altered_sequence' => $alteredSequence,
            'question' => [
                'prompt' => 'Did the change happen near the Beginning or the End?',
                'options' => ['beginning', 'end'],
            ],
            'ground_truth' => [
                'changed_index' => $changedIndex,
                'position' => $position,
            ],
        ];
    }

    /**
     * 1D: MUSICAL FEATURES
     * Generates a short phrase performed with a specific dynamic (forte/piano)
     * and articulation (legato/staccato); user identifies both.
     */
    public function generateMusicalFeatures(int $gradeLevel): array
    {
        $this->assertGradeOneOnly($gradeLevel, 'Musical Features');

        $key = self::GRADE_1_KEYS[array_rand(self::GRADE_1_KEYS)];
        $dynamic = random_int(0, 1) === 0 ? 'forte' : 'piano';
        $articulation = random_int(0, 1) === 0 ? 'legato' : 'staccato';
        $noteSequence = $this->generateStepwiseMelody($key, beatsBudget: 4.0, maxDegreeStep: 2);

        return [
            'module_type' => 'musical_features',
            'key' => $key,
            'note_sequence' => $noteSequence,
            // Client-renderable playback hints - not the answer itself, just how loud/
            // connected to play the notes so it actually sounds forte/piano, legato/staccato.
            'dynamic_hint' => [
                'velocity' => $dynamic === 'forte' ? 100 : 40, // MIDI-style 0-127 loudness
            ],
            'articulation_hint' => [
                // Fraction of each note's written duration that actually sounds before
                // a rest (legato notes ring almost fully; staccato notes are clipped short).
                'duration_multiplier' => $articulation === 'legato' ? 0.95 : 0.5,
            ],
            'question' => [
                'dynamic' => [
                    'prompt' => 'Was the piece loud (Forte) or quiet (Piano)?',
                    'options' => ['forte', 'piano'],
                ],
                'articulation' => [
                    'prompt' => 'Was the piece smooth (Legato) or short and detached (Staccato)?',
                    'options' => ['legato', 'staccato'],
                ],
            ],
            'ground_truth' => [
                'dynamic' => $dynamic,
                'articulation' => $articulation,
            ],
        ];
    }

    /**
     * Shared melody generator used by 1B/1C/1D: a random-walk over scale degrees
     * (never stepping more than $maxDegreeStep degrees at a time, which is what
     * keeps every leap within a 3rd), with random quarter/eighth-note rhythms
     * filling up the given beat budget.
     */
    private function generateStepwiseMelody(string $key, float $beatsBudget, int $maxDegreeStep): array
    {
        $notes = [];
        $currentDegree = 0; // Start on the tonic.
        $beatsUsed = 0.0;

        while ($beatsUsed < $beatsBudget) {
            $remaining = $beatsBudget - $beatsUsed;
            // Pick a quarter (1 beat) or eighth (0.5 beat) note, but never overshoot the budget.
            $duration = ($remaining >= 1.0 && random_int(0, 1) === 0) ? 1.0 : min(0.5, $remaining);

            $note = $this->degreeToNote($key, $currentDegree);
            $notes[] = array_merge($note, ['duration_beats' => $duration]);

            $beatsUsed += $duration;

            // Walk to the next degree, keeping the melody within a compact,
            // beginner-singable range (roughly a 6th either side of the tonic) -
            // a plain random walk with no bound would happily wander a full
            // octave away over just a handful of notes.
            $step = random_int(-$maxDegreeStep, $maxDegreeStep);
            $currentDegree = max(-4, min(4, $currentDegree + $step));
        }

        return $notes;
    }

    /**
     * Converts a scale-degree number (0 = tonic, can be negative or > 6 to move
     * into neighbouring octaves) into a concrete note name + octave, e.g.
     * degreeToNote('C', 2) => ['note_name' => 'E', 'octave' => 4, ...].
     */
    private function degreeToNote(string $key, int $degree, int $octaveOffset = 4): array
    {
        $rootIndex = array_search($key, self::CHROMATIC_NOTES, true);

        // Split the degree into "which octave" and "which of the 7 degrees within it",
        // handling negative degrees correctly (PHP's % can return negative results).
        $octaveShift = (int) floor($degree / 7);
        $degreeInOctave = $degree - ($octaveShift * 7);

        $semitoneOffset = self::MAJOR_SCALE_SEMITONES[$degreeInOctave] + ($octaveShift * 12);
        $absoluteSemitone = ($octaveOffset * 12) + $rootIndex + $semitoneOffset;

        $octave = intdiv($absoluteSemitone, 12);
        $noteIndex = $absoluteSemitone % 12;

        return [
            'note_name' => self::CHROMATIC_NOTES[$noteIndex],
            'octave' => $octave,
            'degree' => $degree,
            'octave_offset' => $octaveOffset,
        ];
    }

    private function assertGradeOneOnly(int $gradeLevel, string $moduleName): void
    {
        if ($gradeLevel !== 1) {
            throw new InvalidArgumentException(
                "{$moduleName} exercise generation is not yet implemented for Grade {$gradeLevel} - only Grade 1's rules exist so far."
            );
        }
    }
}
