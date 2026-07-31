<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Set once the pretest (baseline) assessment is finished - gates
            // entry to normal Free Practice/Transcription so arm-specific
            // feedback and gating can't contaminate the pretest measurement.
            $table->timestamp('baseline_completed_at')->nullable();

            // Mean |cents_deviation| across the fixed baseline pitch-matching
            // trials - same unit as the rolling-average research metric, so
            // it's directly comparable to later posttest/trend values.
            $table->float('baseline_pitch_accuracy_cents')->nullable();

            // correctness_pct from the single fixed baseline transcription item.
            $table->float('baseline_transcription_accuracy_pct')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'baseline_completed_at',
                'baseline_pitch_accuracy_cents',
                'baseline_transcription_accuracy_pct',
            ]);
        });
    }
};
