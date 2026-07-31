<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Marks the single fixed pretest transcription item (and its attempt)
        // as distinct from ordinary practice - generated ungated, outside the
        // AdaptiveSequencingService unlock check, so it can be presented to
        // every participant regardless of study arm before any arm-specific
        // gating logic has ever run for them.
        Schema::table('aural_exercises', function (Blueprint $table) {
            $table->boolean('is_baseline')->default(false);
        });

        Schema::table('aural_module_attempts', function (Blueprint $table) {
            $table->boolean('is_baseline')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('aural_exercises', function (Blueprint $table) {
            $table->dropColumn('is_baseline');
        });

        Schema::table('aural_module_attempts', function (Blueprint $table) {
            $table->dropColumn('is_baseline');
        });
    }
};
