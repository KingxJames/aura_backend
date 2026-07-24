<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

/**
 * Shared single-note Gemini caller - originally QuizController's private
 * generateAuraNote(), extracted so AuralModuleController's debrief/help
 * endpoints can produce the same "FROM AURA" short reactive notes without
 * duplicating the Gemini request/response plumbing.
 */
class GeminiNoteService
{
    public function generateNote(string $prompt, string $fallbackMessage): JsonResponse
    {
        try {
            $apiKey = env('GEMINI_API_KEY');
            $response = Http::withHeaders([
                'Content-Type' => 'application/json'
            ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}", [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]]
                        ],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json'
                        ]
                    ]);

            if ($response->failed()) {
                throw new \Exception("Gemini API communication dropped.");
            }

            $resultText = $response->json()['candidates'][0]['content']['parts'][0]['text'];
            $data = json_decode($resultText, true);

            return response()->json([
                'success' => true,
                'message' => $data['message'] ?? $fallbackMessage,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $fallbackMessage,
            ], 500);
        }
    }
}
