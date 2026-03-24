<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $name = config('app.admin_bootstrap_name', 'Super Admin');
        $email = config('app.admin_bootstrap_email', 'admin@example.com');
        $password = config('app.admin_bootstrap_password', 'password');

        AdminUser::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'role' => AdminUser::ROLE_SUPER_ADMIN,
                'status' => AdminUser::STATUS_ACTIVE
            ]
        );
    }
}
