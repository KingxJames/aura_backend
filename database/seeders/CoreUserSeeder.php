<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CoreUserSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create a predictable student profile for testing your frontend login forms
        User::create([
            'name'              => 'Alex Scholar',
            'username'          => 'alex_scholar', // Added to satisfy migration NOT NULL constraint
            'email'             => 'student@aura.edu',
            'password'          => Hash::make('password123'),
            'email_verified_at' => now(),
        ]);

        // 2. Create a backup professor/admin profile
        User::create([
            'name'              => 'Prof. Clara',
            'username'          => 'prof_clara',  // Added to satisfy migration NOT NULL constraint
            'email'             => 'professor@aura.edu',
            'password'          => Hash::make('password123'),
            'email_verified_at' => now(),
        ]);
    }
}
