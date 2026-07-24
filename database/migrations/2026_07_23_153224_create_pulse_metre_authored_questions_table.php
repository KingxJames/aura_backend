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
        Schema::create('pulse_metre_authored_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_id')->constrained('grades')->onDelete('cascade');

            // Which of the 10 fixed-sequence slots this question fills (1-10).
            $table->unsignedTinyInteger('question_number');

            // { filename, time_signature } for listen_mcq, plus
            // beat_timestamps_ms for the 3 tap phases - see
            // AuralModuleController::buildAuthoredPulseMetrePayload(). Derived
            // fields (downbeat_indices, audible_beat_indices, beats_per_bar)
            // are NOT stored here - computed from time_signature/beat_timestamps_ms.
            $table->jsonb('payload_jsonb');

            $table->timestamps();

            // Exactly one authored question per slot per grade.
            $table->unique(['grade_id', 'question_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_metre_authored_questions');
    }
};
