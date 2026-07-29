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
        Schema::create('exercise_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Polymorphic so both AuralAttempt (aural tab) and AuralModuleAttempt
            // (modules, including Transcription) can carry post-exercise feedback
            // without two near-duplicate tables. Uses the morph map registered in
            // AppServiceProvider (short aliases, not raw class names) over the wire.
            $table->morphs('feedbackable');

            $table->unsignedTinyInteger('rating'); // 1-5
            $table->text('comment')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exercise_feedback');
    }
};
