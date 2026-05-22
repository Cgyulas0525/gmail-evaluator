<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => env('AUTH_DEFAULT_EMAIL', 'admin@gmailevaluator.local')],
            [
                'name' => env('AUTH_DEFAULT_NAME', 'Admin'),
                'password' => Hash::make(env('AUTH_DEFAULT_PASSWORD', 'password')),
                'email_verified_at' => now(),
            ]
        );
    }
}
