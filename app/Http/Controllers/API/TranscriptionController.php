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
        // 1. Enforce strict validation on user checking
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        // 2. Fetch records efficiently
        $transcriptions = Transcription::where('user_id', $request->query('user_id'))
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($item) {
                // Ensure front-end friendly readable date values are appended
                $item->formatted_date = $item->created_at->format('M d, Y • g:i A');
                return $item;
            });

        // 3. Return payload structure tailored for a clean history listing screen
        return response()->json([
            'success' => true,
            'count' => $transcriptions->count(),
            'data' => $transcriptions,
        ], 200);
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

            // Save the image upfront to get a stable resource URL
            $permanentPath = $file->store('transcriptions_sheets', 'public');
            $uploadedImageUrl = Storage::url($permanentPath);
            $absolutePath = Storage::disk('public')->path($permanentPath);

            $imageBytes = '';
            $filename = $file->getClientOriginalName();

            // Handle PDF conversion layout
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

            $baseUrl = env('OMR_API_URL', 'http://127.0.0.1:8000');
            $modelEndpoint = rtrim($baseUrl, '/') . '/api/v1/transcribe';

            $response = Http::attach(
                'file',
                $imageBytes,
                $filename
            )->timeout(300)->post($modelEndpoint);

            if ($response->failed()) {
                Storage::disk('public')->delete($permanentPath);
                throw new \Exception("OMR microservice error: " . $response->status());
            }

            $omrOutput = $response->json();
            $digitalNotationPayload = $omrOutput['notation'] ?? "X:1\nM:4/4\nK:C\nC4";

            // Save straight to your DB schema logs
            $transcriptionHistory = Transcription::create([
                'user_id' => $request->input('user_id'),
                'uploaded_image_url' => $uploadedImageUrl,
                'generated_musicxml' => $digitalNotationPayload,
                'generated_midi' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sheet music transcribed and saved to history.',
                'data' => $transcriptionHistory
            ], 200);
        } catch (\Exception $e) {
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
