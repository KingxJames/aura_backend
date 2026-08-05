<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AuralAttempt;
use App\Models\OpusLevel;
use App\Services\PitchAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpusController extends Controller
{
    public function __construct(
        private PitchAnalysisService $pitchAnalysis
    ) {
    }

    /**
     * OPUS SYLLABUS OVERVIEW - All 5 levels with this user's progress and lock state.
     * GET /api/v1/aural/opus
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $levels = OpusLevel::orderBy('level_number')->get();

        $previousCompleted = true;
        $data = $levels->map(function (OpusLevel $level) use ($user, &$previousCompleted) {
            $passedNotes = $level->passedNotesFor($user);
            $isCompleted = count($passedNotes) >= count($level->target_notes);
            $isUnlocked = $level->level_number === 1 || $previousCompleted;

            $previousCompleted = $isCompleted;

            return [
                'id' => $level->id,
                'level_number' => $level->level_number,
                'title' => $level->title,
                'description' => $level->description,
                'target_notes' => $level->target_notes,
                'tolerance_cents' => $level->tolerance_cents,
                'passed_notes' => $passedNotes,
                'total_notes' => count($level->target_notes),
                'is_completed' => $isCompleted,
                'is_unlocked' => $isUnlocked,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * OPUS SYLLABUS ATTEMPT - Sing-match one required note for a specific Opus level.
     * POST /api/v1/aural/opus/{opusLevel}/attempt
     */
    public function attempt(Request $request, OpusLevel $opusLevel): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|mimes:wav,mp3,m4a,ogg|max:10240', // Max 10MB clip
            'target_note' => 'required|string|max:5',
        ]);

        if (!in_array($request->target_note, $opusLevel->target_notes, true)) {
            return response()->json([
                'success' => false,
                'message' => 'That note is not part of this Opus level\'s requirements.',
            ], 422);
        }

        $user = $request->user();

        if ($opusLevel->level_number > 1) {
            $previousLevel = OpusLevel::where('level_number', $opusLevel->level_number - 1)->first();

            if ($previousLevel && !$previousLevel->isCompletedBy($user)) {
                return response()->json([
                    'success' => false,
                    'message' => "Complete {$previousLevel->title} before attempting {$opusLevel->title}.",
                ], 403);
            }
        }

        $filePath = $request->file('audio')->store('audio/vocal_tests');
        $absolutePath = storage_path('app/private/' . $filePath);

        $result = $this->pitchAnalysis->analyze($absolutePath, $request->target_note);

        if (!$result || isset($result['success']) && !$result['success']) {
            return $this->pitchAnalysis->failureResponse($result);
        }

        $passed = abs($result['cents_deviation']) <= $opusLevel->tolerance_cents;
        $wasCompletedBefore = $opusLevel->isCompletedBy($user);

        $feedback = $passed
            ? "Well matched - that note meets Opus {$opusLevel->level_number} accuracy standards."
            : "Not quite within range for Opus {$opusLevel->level_number} - listen closely and try again.";

        $attempt = AuralAttempt::create([
            'user_id' => $user->id,
            'context' => 'opus_syllabus',
            'opus_level_id' => $opusLevel->id,
            'audio_path' => $filePath,
            'target_note' => $request->target_note,
            'detected_frequency' => $result['detected_frequency'],
            'cents_deviation' => $result['cents_deviation'],
            'feedback_text' => $feedback,
        ]);

        $wasCompletedBefore = $opusLevel->isCompletedBy($user);
        $passedNotes = $opusLevel->passedNotesFor($user);
        $isCompleted = count($passedNotes) >= count($opusLevel->target_notes);

        return response()->json([
            'success' => true,
            'message' => 'Opus attempt recorded.',
            'data' => [
                'attempt' => $attempt,
                'passed' => $passed,
                'level_progress' => [
                    'passed_notes' => $passedNotes,
                    'total_notes' => count($opusLevel->target_notes),
                    'is_completed' => $isCompleted,
                ],
                'just_unlocked_next' => $isCompleted && !$wasCompletedBefore,
            ]
        ], 200);
    }
}
