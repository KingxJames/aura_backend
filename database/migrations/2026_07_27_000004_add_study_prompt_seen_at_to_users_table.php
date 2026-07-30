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
            // Separate from study_enrolled_at - a user can see the consent
            // screen and decline, and still shouldn't be shown it again.
            // Tracked per-account (not per-device) so switching devices, or a
            // second account on the same device, doesn't skip/repeat it wrongly.
            $table->timestamp('study_prompt_seen_at')->nullable()->after('study_enrolled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('study_prompt_seen_at');
        });
    }
};
