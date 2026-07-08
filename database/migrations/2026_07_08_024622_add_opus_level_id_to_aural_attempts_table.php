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
            $table->foreignId('opus_level_id')->nullable()->after('context')->constrained('opus_levels')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aural_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opus_level_id');
        });
    }
};
