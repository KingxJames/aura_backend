<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_progress', function (Blueprint $table) {
            $table->integer('current_streak')->default(0);
            $table->string('current_tier')->default('standard'); // 'standard' or 'advanced'
        });
    }

    public function down(): void
    {
        Schema::table('user_progress', function (Blueprint $table) {
            $table->dropColumn(['current_streak', 'current_tier']);
        });
    }
};