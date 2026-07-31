<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedagogical Sub-Question 2 (flow / cognitive overload): a short,
     * un-validated custom check-in - not a formal psychometric scale, just
     * two quick self-report items - shown once per calendar day, the first
     * time a participant finishes an Aural Training attempt that day.
     * Scoped to Aural Training only (Free Practice + Transcription); does
     * not touch the AI Tutor chat.
     */
    public function up(): void
    {
        Schema::create('flow_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // 1 (not at all) - 5 (completely absorbed) - flow proxy.
            $table->unsignedTinyInteger('absorption_rating');

            // 1 (very easy) - 5 (very demanding) - cognitive-load proxy.
            $table->unsignedTinyInteger('challenge_rating');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_checkins');
    }
};
