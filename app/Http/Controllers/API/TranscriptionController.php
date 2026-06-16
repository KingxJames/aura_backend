<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Transcription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class TranscriptionController extends Controller
{
    /**
     * GET /api/v1/transcriptions?user_id={id}
     * List all transcriptions for a user, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $transcriptions = Transcription::where('user_id', $request->query('user_id'))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'count' => $transcriptions->count(),
            'data' => $transcriptions,
        ]);
    }

    /**
     * POST /api/v1/transcriptions
     * Store a new transcription result manually.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'uploaded_image_url' => 'required|string|max:2048',
            'generated_musicxml' => 'nullable|string', // Consider changing to 'generated_abc' in schema
            'generated_midi' => 'nullable|string',
        ]);

        $transcription = Transcription::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Transcription stored successfully.',
            'data' => $transcription,
        ], 201);
    }

    /**
     * GET /api/v1/transcriptions/{transcription}
     * Show a single transcription.
     */
    public function show(Transcription $transcription): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $transcription,
        ]);
    }

    /**
     * DELETE /api/v1/transcriptions/{transcription}
     * Delete a transcription record.
     */
    public function destroy(Transcription $transcription): JsonResponse
    {
        $transcription->delete();

        return response()->json([
            'success' => true,
            'message' => 'Transcription deleted successfully.',
        ]);
    }

    /**
     * PROCESS SHEET MUSIC IMAGE FOR DIGITAL OMR OUTPUT AND SAVE TO HISTORY
     * POST /api/v1/transcribe/upload
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'sheet_music_image' => 'required|file|mimes:jpeg,jpg,png,pdf|max:12288',
        ]);

        try {
            $file = $request->file('sheet_music_image');
            $extension = strtolower($file->getClientOriginalExtension());

            // 1. Permanently save the image upfront to get a stable resource URL
            $permanentPath = $file->store('transcriptions_sheets', 'public');
            $uploadedImageUrl = Storage::disk('public')->url($permanentPath);
            $absolutePath = Storage::disk('public')->path($permanentPath);

            $imageBytes = '';
            $filename = $file->getClientOriginalName();

            // 2. Handle conversion if the input document is a PDF
            if ($extension === 'pdf') {
                $imagick = new \Imagick();
                $imagick->setResolution(200, 200);
                $imagick->readImage($absolutePath . '[0]');
                $imagick->setImageFormat('png');

                $imageBytes = $imagick->getImageBlob();
                $filename = pathinfo($filename, PATHINFO_FILENAME) . '.png';

                $imagick->clear();
                $imagick->destroy();
            } else {
                $imageBytes = Storage::disk('public')->get($permanentPath);
            }

            // 3. Fallback routing checking for Docker container networks vs local processes
            // Best practice: Add OMR_API_URL=http://host.docker.internal:8000 to your .env file
            $baseUrl = env('OMR_API_URL', 'http://127.0.0.1:8000');
            $modelEndpoint = rtrim($baseUrl, '/') . '/api/v1/transcribe';

            // 4. Fire the stream request across the loop with a safe 5-minute timeout window
            $response = Http::attach(
                'file',       // Matches FastAPI variable: file: UploadFile = File(...)
                $imageBytes,
                $filename
            )->timeout(300)->post($modelEndpoint);

            // 5. Evaluate if the background Python runtime succeeded
            if ($response->failed()) {
                Storage::disk('public')->delete($permanentPath);
                throw new \Exception("Local OMR Processing microservice error: " . $response->status() . " - " . $response->body());
            }

            // 6. Extract the structured string returned by your ViT-GPT2 model
            $omrOutput = $response->json();
            $digitalNotationPayload = $omrOutput['notation'] ?? "X:1\nM:4/4\nK:C\nC4";

            // 7. Write the record into your database history mapping
            $transcriptionHistory = Transcription::create([
                'user_id' => $request->input('user_id'),
                'uploaded_image_url' => $uploadedImageUrl,
                'generated_musicxml' => $digitalNotationPayload, // Storing ABC format string here safely
                'generated_midi' => null,
            ]);

            // 8. Return complete runtime payload pack back to user client viewport
            return response()->json([
                'success' => true,
                'message' => 'Sheet music layout successfully transcribed and saved to history.',
                'notation_format' => 'ABC_Notation',
                'digital_score' => $digitalNotationPayload,
                'history_record' => $transcriptionHistory
            ], 200);

        } catch (\Exception $e) {
            // Clean up storage leaks if execution crashes unexpectedly
            if (isset($permanentPath)) {
                Storage::disk('public')->delete($permanentPath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Optical Music Recognition parsing failure.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}