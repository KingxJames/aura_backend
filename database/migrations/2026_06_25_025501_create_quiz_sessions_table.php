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
        Schema::create('quiz_sessions', function (Blueprint $table) {
            $table->id();

            // Relate the session directly to the authenticated student
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Relate the session to the specific quiz block being executed
            $table->foreignId('quiz_id')->constrained()->onDelete('cascade');

            // Track the real-time adaptive engine values
            $table->integer('current_streak')->default(0); // Increments on correct, resets on wrong
            $table->integer('lives_remaining')->default(3); // Gamified heart counter (starts at 3)

            // Track the scale state (e.g., 1 = Easy, 2 = Medium, 3 = Hard)
            $table->integer('difficulty_tier')->default(2); // Always start students on Medium

            // Track the state lifecycle of this specific attempt
            $table->string('status')->default('active'); // active, completed, failed (out of lives)

            // For analytical logging
            $table->integer('total_questions_answered')->default(0);
            $table->integer('total_correct_answers')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quiz_sessions');
    }
};
