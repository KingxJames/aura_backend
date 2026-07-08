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
            $table->string('context')->default('practice')->after('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('warm_up_streak')->default(0);
            $table->unsignedInteger('warm_up_longest_streak')->default(0);
            $table->date('last_warm_up_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aural_attempts', function (Blueprint $table) {
            $table->dropColumn('context');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['warm_up_streak', 'warm_up_longest_streak', 'last_warm_up_date']);
        });
    }
};
