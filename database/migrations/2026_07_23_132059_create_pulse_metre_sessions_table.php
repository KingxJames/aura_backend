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
        Schema::create('pulse_metre_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('grade_id')->constrained('grades')->onDelete('cascade');

            // Which of the 10 questions in the phase arc the user is currently on.
            // Drives phase selection: 1-3 listen_mcq, 4-6 downbeat_tap, 7-9 muted_bar_tap, 10 boss.
            $table->unsignedTinyInteger('question_number')->default(1);

            $table->string('status')->default('active'); // active | completed

            $table->unsignedTinyInteger('correct_count')->default(0);

            $table->timestamps();

            // Speeds up "find my active session for this grade" lookups.
            $table->index(['user_id', 'grade_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_metre_sessions');
    }
};
