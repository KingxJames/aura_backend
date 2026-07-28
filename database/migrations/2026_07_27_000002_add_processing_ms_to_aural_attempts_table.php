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
        Schema::table('aural_attempts', function (Blueprint $table) {
            $table->unsignedInteger('processing_ms')->nullable()->after('cents_deviation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aural_attempts', function (Blueprint $table) {
            $table->dropColumn('processing_ms');
        });
    }
};
