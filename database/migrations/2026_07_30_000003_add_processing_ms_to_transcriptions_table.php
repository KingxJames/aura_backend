<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            // OMR pipeline latency (Technical Sub-Question 1: DSP/OMR latency
            // on a mobile-centric architecture) - wall-clock ms spent forwarding
            // the image to the Python OMR microservice and getting a result back.
            $table->unsignedInteger('processing_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropColumn('processing_ms');
        });
    }
};
