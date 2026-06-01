<?php

use Illuminate\Support\Facades\Route;

// Import all required application controllers
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\AuralController;
use App\Http\Controllers\API\QuizController;
use App\Http\Controllers\API\TutorController;
use App\Http\Controllers\API\TranscriptionController;

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

    // --- Curriculum, Theory, & Progress (Grades & Quizzes Tabs) ---
    Route::get('/v1/curriculum', [QuizController::class, 'index']);                        // Fetch Grades 1-5 syllabus layout
    Route::get('/v1/curriculum/quiz/{id}', [QuizController::class, 'showQuiz']);           // Fetch quiz question sets
    Route::post('/v1/curriculum/quiz/submit', [QuizController::class, 'submitQuiz']);       // Log a fresh quiz score
    Route::get('/v1/curriculum/progress', [QuizController::class, 'studentProgress']);     // Fetch user grade book / charts data
    Route::delete('/v1/curriculum/progress/{id}', [QuizController::class, 'destroyProgress']); // Reset a score history record
    Route::get('/v1/curriculum/dashboard-recommendations', [QuizController::class, 'getDashboardRecommendations']);

    // --- AI Chat Tutor (Tutor Tab) ---
    Route::post('/v1/tutor/chat', [TutorController::class, 'chat']);         // Ask Gemini a question and store log
    Route::get('/v1/tutor/history', [TutorController::class, 'history']);     // Sync chat bubble histories
    Route::delete('/v1/tutor/history', [TutorController::class, 'clearHistory']); // Clear user conversation row logs

    // --- Optical Sheet Music Transcription (Transcriber Tab) ---
    Route::post('/v1/transcribe/upload', [TranscriptionController::class, 'upload']); // Upload score image for OMR output

});