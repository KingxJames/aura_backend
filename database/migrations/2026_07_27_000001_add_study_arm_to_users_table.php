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
        Schema::table('users', function (Blueprint $table) {
            $table->enum('study_arm', ['control', 'experimental'])->nullable()->after('current_grade_level');
            $table->timestamp('study_enrolled_at')->nullable()->after('study_arm');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['study_arm', 'study_enrolled_at']);
        });
    }
};
