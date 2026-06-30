<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('quizzes')->onDelete('cascade');
            $table->integer('question_id'); // matches the "id" field inside content_jsonb
            $table->timestamps();

            $table->unique(['quiz_id', 'question_id']);
        });

        // sentence-transformers/all-MiniLM-L6-v2 produces 384-dimension embeddings.
        DB::statement('ALTER TABLE question_embeddings ADD COLUMN embedding vector(384);');
    }

    public function down(): void
    {
        Schema::dropIfExists('question_embeddings');
    }
};
