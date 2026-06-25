<?php

use Illuminate\Support\Facades\Route;

// Import all required application controllers
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\AuralController;
use App\Http\Controllers\API\QuizController;
use App\Http\Controllers\API\TutorController;
use App\Http\Controllers\API\TranscriptionController;
use App\Http\Controllers\API\GradeTheoryController;
use App\Http\Controllers\API\QuizSessionController;

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

    // --- Aural Analysis Engine (Aural Tab) ---
    Route::get('/v1/aural', [AuralController::class, 'index']);           // List historic singing attempts
    Route::post('/v1/aural/analyze', [AuralController::class, 'store']);     // Process new audio via Python
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

    // 2. ADD YOUR NEW THEORY ENDPOINTS HERE
    Route::get('/v1/theory/questions', [GradeTheoryController::class, 'getQuestions']);
    Route::post('/v1/theory/evaluate', [GradeTheoryController::class, 'evaluateAnswers']);
    // Gamified Adaptive Quiz Session Routes
    Route::post('/quiz/session/start', [QuizSessionController::class, 'start']);
    Route::post('/quiz/session/step', [QuizSessionController::class, 'step']);
    Route::post('/quiz/session/finalize', [QuizSessionController::class, 'finalize']);

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
