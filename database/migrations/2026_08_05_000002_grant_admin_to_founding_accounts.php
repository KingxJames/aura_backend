<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // James Faber's accounts (across his personal, university, and school
    // logins) - grants access to the Study Monitor dashboard in Settings.
    private const FOUNDING_ADMIN_EMAILS = [
        'jaafaber@gmail.com',
        'james.faber@ub.edu.bz',
        'james.f@students.opit.com',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereIn('email', self::FOUNDING_ADMIN_EMAILS)
            ->update(['is_admin' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')
            ->whereIn('email', self::FOUNDING_ADMIN_EMAILS)
            ->update(['is_admin' => false]);
    }
};
