<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Accounts provisioned via Google sign-in before the `provider` column
     * existed all defaulted to 'local'. The Google flow is the only path
     * that ever stores a googleusercontent.com avatar, so it's a safe
     * signal to backfill those rows to the correct provider.
     */
    public function up(): void
    {
        DB::table('users')
            ->where('profile_picture', 'ilike', '%googleusercontent.com%')
            ->update(['provider' => 'google']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')
            ->where('profile_picture', 'ilike', '%googleusercontent.com%')
            ->update(['provider' => 'local']);
    }
};
