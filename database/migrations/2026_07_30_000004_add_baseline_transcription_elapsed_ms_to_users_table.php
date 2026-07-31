<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Speed half of the baseline transcription snapshot (accuracy half
            // is baseline_transcription_accuracy_pct) - needed so a posttest
            // speed comparison has a true pretest to measure against, matching
            // the primary RQ's "transcription speed" outcome.
            $table->unsignedInteger('baseline_transcription_elapsed_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('baseline_transcription_elapsed_ms');
        });
    }
};
