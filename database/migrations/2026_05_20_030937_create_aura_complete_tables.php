<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Enable pgvector extension inside your PostgreSQL container instance
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector;');

        // 1. CORE USER MANAGEMENT
        // Note: Laravel includes a default 'users' migration. Ensure its 'id' is changed to uuid or update it here.
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('email')->unique();
                $table->string('username');
                $table->string('current_grade_level')->default('Grade 1');
                $table->timestamps(); // Handles created_at and updated_at
            });
        }

        // 2. CURRICULUM & THEORY (Grades & Quizzes Tabs)
        Schema::create('grades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->integer('level_number')->unique();
            $table->text('description');
            $table->text('syllabus_focus');
            $table->timestamps();
        });

        Schema::create('quizzes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('grade_id')->constrained('grades')->onDelete('cascade');
            $table->string('title');
            $table->text('description');
            $table->jsonb('content_jsonb'); // Native JSON storage for structural dynamic test engine
            $table->timestamps();
        });

        // 3. RESEARCH TRACKING (Home & Progress Analytics)
        Schema::create('user_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('quiz_id')->constrained('quizzes')->onDelete('cascade');
            $table->decimal('score', 5, 2);
            $table->timestamps();
        });

        // 4. AURAL ANALYSIS ENGINE (Aural Tab)
        Schema::create('aural_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('target_note');
            $table->decimal('detected_frequency', 10, 2);
            $table->decimal('cents_deviation', 6, 2);
            $table->text('feedback_text')->nullable();
            $table->string('audio_path')->nullable();
            $table->timestamps();
        });

        // 5. TUTOR INTERACTION LOGS (Tutor Tab)
        Schema::create('tutor_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('message_type'); // 'user' or 'ai'
            $table->text('content');
            // Raw SQL statement execution to add pgvector standard mapping safely
            $table->timestamps();
        });
        DB::statement('ALTER TABLE tutor_conversations ADD COLUMN embedding_vector vector(1536);');

        // 6. OPTICAL TRANSCRIPTION DATA (Transcriber Tab)
        Schema::create('transcriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('uploaded_image_url');
            $table->text('generated_musicxml')->nullable();
            $table->binary('generated_midi')->nullable(); // Maps to PostgreSQL bytea blob for raw playback streams
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcriptions');
        Schema::dropIfExists('tutor_conversations');
        Schema::dropIfExists('aural_attempts');
        Schema::dropIfExists('user_progress');
        Schema::dropIfExists('quizzes');
        Schema::dropIfExists('grades');
        Schema::dropIfExists('users');
    }
};