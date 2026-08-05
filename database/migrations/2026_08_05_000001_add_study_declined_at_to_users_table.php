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
            // Distinct from study_prompt_seen_at (which fires just from
            // viewing the screen) - this is an explicit, recorded decision
            // not to join, so the consent gate can tell "made a choice"
            // apart from "saw the screen but never decided" and stop
            // blocking the rest of the app once either decision exists.
            $table->timestamp('study_declined_at')->nullable()->after('study_prompt_seen_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('study_declined_at');
        });
    }
};
