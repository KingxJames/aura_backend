<?php

use Illuminate\Support\Facades\Route;

// Import all required application controllers
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\AuralController;
use App\Http\Controllers\API\AuralModuleController;
use App\Http\Controllers\API\OpusController;
use App\Http\Controllers\API\QuizController;
use App\Http\Controllers\API\TutorController;
use App\Http\Controllers\API\TranscriptionController;
use App\Http\Controllers\API\GradeTheoryController;
use App\Http\Controllers\API\QuizSessionController;
use App\Http\Controllers\API\PushTokenController;
use App\Http\Controllers\API\StudyEnrollmentController;
use App\Http\Controllers\API\StudyBaselineController;
use App\Http\Controllers\API\ExerciseFeedbackController;
use App\Http\Controllers\API\FlowCheckinController;
use App\Http\Controllers\API\StudyDashboardController;

/*
|--------------------------------------------------------------------------
| Aura API Master Route Map
|--------------------------------------------------------------------------
*/

// =========================================================================
// 1. PUBLIC AUTHENTICATION GATES (Accessible without a token)
// =========================================================================
Route::post('/v1/auth/register', [AuthController::class, 'register']); // Traditional email signup
Route::post('/v1/auth/login', [AuthController::class, 'login']);       // Traditional email login
Route::get('/v1/auth/google/start', [AuthController::class, 'startGoogleAuth']); // Browser OAuth bootstrap
Route::get('/v1/auth/google/callback', [AuthController::class, 'handleGoogleCallback']); // Google OAuth callback bridge
Route::post('/v1/auth/google', [AuthController::class, 'handleGoogleSignIn']); // 1-Tap Gmail login

// =========================================================================
// 2. PROTECTED CORE APPLICATION API (Requires valid Sanctum Device Token)
// =========================================================================
Route::middleware('auth:sanctum')->group(function () {

    // --- Session Termination ---
    Route::post('/v1/auth/logout', [AuthController::class, 'logout']); // Revoke token & sign out

    // --- Profile ---
    Route::post('/v1/user/avatar', [AuthController::class, 'uploadAvatar']); // Upload a local-account profile picture
    Route::post('/v1/user/password', [AuthController::class, 'changePassword']); // Change password (local accounts only)
    Route::post('/v1/user/push-token', [PushTokenController::class, 'store']); // Register/reassign this device's Expo push token
    Route::delete('/v1/user/push-token', [PushTokenController::class, 'destroy']); // Remove this device's push token

    // --- Research Study Enrollment ---
    Route::get('/v1/study/status', [StudyEnrollmentController::class, 'status']); // Whether the user is already enrolled + has seen the consent screen (no arm revealed)
    Route::post('/v1/study/mark-prompt-seen', [StudyEnrollmentController::class, 'markPromptSeen']); // Records that the consent screen was shown, regardless of the decision
    Route::post('/v1/study/decline', [StudyEnrollmentController::class, 'decline']); // Explicit decision not to join - unblocks the app the same as enrolling does
    Route::post('/v1/study/enroll', [StudyEnrollmentController::class, 'enroll']); // Consent-gated study arm assignment (block-randomized)

    // --- Baseline (Pretest) Assessment - required before arm-specific feedback/gating activates ---
    Route::get('/v1/study/baseline/status', [StudyBaselineController::class, 'status']); // Progress through the fixed pitch trials + transcription item
    Route::post('/v1/study/baseline/pitch-attempt', [StudyBaselineController::class, 'pitchAttempt']); // Next fixed-sequence pitch trial
    Route::get('/v1/study/baseline/transcription-exercise', [StudyBaselineController::class, 'transcriptionExercise']); // Fixed, ungated transcription item
    Route::post('/v1/study/baseline/transcription-attempt', [StudyBaselineController::class, 'transcriptionAttempt']); // Grade the baseline transcription item
    Route::post('/v1/study/baseline/complete', [StudyBaselineController::class, 'complete']); // Aggregate + finalize baseline metrics

    // --- Post-Exercise Qualitative Feedback ---
    Route::post('/v1/feedback', [ExerciseFeedbackController::class, 'store']); // Submit/update a 1-5 rating + optional comment for an attempt

    // --- Flow / Cognitive-Load Check-in (Pedagogical Sub-Question 2, Aural Training only) ---
    Route::get('/v1/flow-checkin/today', [FlowCheckinController::class, 'today']); // Whether today's check-in is already done
    Route::post('/v1/flow-checkin', [FlowCheckinController::class, 'store']); // Submit today's check-in (idempotent per day)

    // --- Researcher-Only Study Dashboard (admin-gated, see EnsureIsAdmin) ---
    Route::middleware('admin')->group(function () {
        Route::get('/v1/study/admin/enrollment-summary', [StudyDashboardController::class, 'enrollmentSummary']); // Per-arm enrollment counts vs. target/floor
        Route::get('/v1/study/admin/attrition', [StudyDashboardController::class, 'attrition']); // Per-participant completed-session counts, flagged if at risk
        Route::get('/v1/study/admin/progress', [StudyDashboardController::class, 'progress']); // Per-participant baseline vs. current pitch + transcription accuracy
        Route::delete('/v1/study/admin/participants/{user}', [StudyDashboardController::class, 'deleteParticipant']); // Remove a throwaway/test participant account
    });

    // --- Aural Analysis Engine (Aural Tab) ---
    Route::get('/v1/aural', [AuralController::class, 'index']);           // List historic singing attempts
    Route::post('/v1/aural/analyze', [AuralController::class, 'store']);     // Process new audio via Python
    Route::post('/v1/aural/warm-up', [AuralController::class, 'warmUp']);       // Tuning Fork daily warm-up
    Route::get('/v1/aural/warm-up/today', [AuralController::class, 'warmUpStatus']); // Today's warm-up + streak status
    Route::get('/v1/aural/accuracy-trend', [AuralController::class, 'accuracyTrend']); // Rolling-window MAE trajectory (research metric)
    Route::get('/v1/aural/opus', [OpusController::class, 'index']);           // Opus Syllabus overview + progress
    Route::post('/v1/aural/opus/{opusLevel}/attempt', [OpusController::class, 'attempt']); // Submit a note attempt for a level

    // --- Grade-Divided Aural Training Modules (1A-1D: Pulse & Metre, Echo Singing, Spotting the Difference, Musical Features, Transcription) ---
    Route::get('/v1/aural/modules/transcription/unlock-status', [AuralModuleController::class, 'transcriptionUnlockStatus']); // Adaptive-sequencing unlock progress (experimental arm only)
    Route::get('/v1/aural/modules/transcription/speed-trend', [AuralModuleController::class, 'transcriptionSpeedTrend']); // Transcription elapsed-time trajectory (research metric)
    Route::get('/v1/aural/modules/{moduleType}/exercise', [AuralModuleController::class, 'generateExercise']); // Procedurally generate a new exercise for a grade
    Route::post('/v1/aural/modules/exercises/{auralExercise}/attempt', [AuralModuleController::class, 'submitAttempt']); // Submit + grade an attempt
    Route::get('/v1/aural/modules/attempts', [AuralModuleController::class, 'history']); // Past attempts, filterable by grade/module
    Route::post('/v1/aural/modules/debrief', [AuralModuleController::class, 'debrief']); // AI note reacting to a completed round
    Route::post('/v1/aural/modules/help', [AuralModuleController::class, 'help']); // AI study tip for a struggling module

    Route::get('/v1/aural/{id}', [AuralController::class, 'show']);       // View single pitch evaluation
    Route::put('/v1/aural/{id}', [AuralController::class, 'update']);     // Edit notes/comments on an attempt
    Route::delete('/v1/aural/{id}', [AuralController::class, 'destroy']); // Delete an attempt record

    // --- Curriculum, Theory, & Progress ---
    Route::get('/v1/curriculum', [QuizController::class, 'index']);
    Route::get('/v1/curriculum/quiz/{id}', [QuizController::class, 'showQuiz']);
    Route::post('/v1/curriculum/quiz/submit', [QuizController::class, 'submitQuiz']);
    Route::get('/v1/curriculum/progress', [QuizController::class, 'studentProgress']);
    Route::delete('/v1/curriculum/progress/{id}', [QuizController::class, 'destroyProgress']);
    Route::get('/v1/curriculum/dashboard-recommendations', [QuizController::class, 'getDashboardRecommendations']);
    Route::post('/v1/curriculum/topic-debrief', [QuizController::class, 'topicDebrief']);
    Route::post('/v1/curriculum/topic-help', [QuizController::class, 'topicHelp']);

    // 2. ADD YOUR NEW THEORY ENDPOINTS HERE
    Route::get('/v1/theory/questions', [GradeTheoryController::class, 'getQuestions']);
    Route::post('/v1/theory/evaluate', [GradeTheoryController::class, 'evaluateAnswers']);
    // Gamified Adaptive Quiz Session Routes
    Route::post('/quiz/session/start', [QuizSessionController::class, 'start']);
    Route::post('/quiz/session/step', [QuizSessionController::class, 'step']);
    Route::post('/quiz/session/finalize', [QuizSessionController::class, 'finalize']);
    Route::post('/quiz/session/ai-challenge', [QuizSessionController::class, 'aiChallenge']);
    Route::post('/quiz/session/ai-challenge/answer', [QuizSessionController::class, 'aiChallengeAnswer']);

    // --- AI Chat Tutor (Tutor Tab) ---
    Route::post('/v1/tutor/chat', [TutorController::class, 'chat']);         // Ask Gemini a question and store log
    Route::get('/v1/tutor/conversations', [TutorController::class, 'conversations']); // List saved chat threads
    Route::delete('/v1/tutor/conversations', [TutorController::class, 'clearConversations']); // Clear all chat threads for a user
    Route::delete('/v1/tutor/conversations/{conversationId}', [TutorController::class, 'deleteConversation']); // Delete one specific chat thread
    Route::get('/v1/tutor/history', [TutorController::class, 'history']);     // Sync chat bubble histories
    Route::delete('/v1/tutor/history', [TutorController::class, 'clearHistory']); // Backward-compatible clear endpoint

    // --- Optical Sheet Music Transcription (Transcriber Tab) ---
    Route::post('/v1/transcriptions/upload', [TranscriptionController::class, 'upload']); // Upload score image for OMR output
    Route::get('/v1/transcriptions', [TranscriptionController::class, 'index']); // List all transcriptions for a user
    Route::post('/v1/transcriptions', [TranscriptionController::class, 'store']); // Store a new transcription result
    Route::get('/v1/transcriptions/{transcription}', [TranscriptionController::class, 'show']); // Show a single transcription
    Route::delete('/v1/transcriptions/{transcription}', [TranscriptionController::class, 'destroy']); // Delete a transcription record
});
