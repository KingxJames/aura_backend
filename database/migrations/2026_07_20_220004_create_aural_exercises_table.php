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
        Schema::create('aural_exercises', function (Blueprint $table) {
            $table->id();

            // Exercises are generated per-request for one specific user (infinite
            // procedural variation, not a shared/reusable bank), so they belong
            // directly to that user rather than being looked up by grade alone.
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('grade_id')->constrained('grades')->onDelete('cascade');

            // Which of the 4 Aural modules this exercise belongs to:
            // pulse_metre | echo_singing | spot_difference | musical_features
            $table->string('module_type');

            // Holds BOTH the generated exercise content (what the client renders/plays)
            // AND its ground-truth answer key together, in one JSON blob. This mirrors
            // how Quiz.content_jsonb already isn't pre-filtered before being sent to the
            // client (see QuizController::showQuiz) - we're not introducing new hiding
            // logic that the rest of the app doesn't already have.
            $table->jsonb('payload_jsonb');

            $table->timestamps();

            // Speeds up "fetch my most recent exercise of this type" lookups.
            $table->index(['user_id', 'module_type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aural_exercises');
    }
};
