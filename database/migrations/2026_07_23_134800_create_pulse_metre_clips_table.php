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
        Schema::create('pulse_metre_clips', function (Blueprint $table) {
            $table->id();

            // Relative filename under storage/app/public/audio/pulse_metre/ -
            // not a full path, so moving the storage root doesn't break rows.
            $table->string('filename')->unique();

            $table->string('time_signature'); // '2/4' | '3/4'

            $table->string('label')->nullable();
            $table->string('source')->nullable();
            $table->string('license')->nullable();
            $table->text('attribution')->nullable();

            $table->timestamps();

            // Speeds up "pick a random clip for this time signature" lookups.
            $table->index('time_signature');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_metre_clips');
    }
};
