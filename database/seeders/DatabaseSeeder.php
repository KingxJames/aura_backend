<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Sequence Order is Critical: Users and Curriculum must exist before logging activities!
        $this->call([
            CoreUserSeeder::class,
            ActivityLogSeeder::class,
            GradeOneQuizSeeder::class,
        ]);
    }
}
