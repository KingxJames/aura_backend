<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('aural_module_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('aural_exercise_id')->constrained('aural_exercises')->onDelete('cascade');

            // Denormalized copy of the parent exercise's module_type, so we can filter/report
            // on attempts without always joining back to aural_exercises. Mirrors the existing
            // 'context' string column style already used on the aural_attempts table.
            $table->string('module_type');

            // What the user actually submitted. Shape depends on module_type:
            // - pulse_metre: { tap_timestamps_ms: [...], selected_time_signature: '3/4' }
            // - spot_difference: { selected_position: 'beginning' | 'end' }
            // - musical_features: { selected_dynamic: 'forte', selected_articulation: 'legato' }
            // - echo_singing: { } (audio is stored separately in audio_path below)
            $table->jsonb('user_response');

            // Nullable because Echo Singing (1B) grading is stubbed for now - we record
            // the attempt but don't yet know if it was correct.
            $table->boolean('is_correct')->nullable();

            // Free-form scoring breakdown, e.g. per-tap timing deltas and accuracy % for
            // pulse_metre, or a simple correct/incorrect field breakdown for the MCQ modules.
            $table->jsonb('score_details')->nullable();

            // Only populated for echo_singing, where the user's response is a sung recording.
            $table->string('audio_path')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aural_module_attempts');
    }
};
