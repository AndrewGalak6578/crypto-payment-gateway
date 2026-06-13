<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $name = config('app.admin_bootstrap_name', 'Super Admin');
        $email = config('app.admin_bootstrap_email', 'admin@example.com');
        $password = (string) config('app.admin_bootstrap_password', '');

        if ($password === '' || $password === 'password') {
            throw new RuntimeException('Set ADMIN_BOOTSTRAP_PASSWORD to a non-default value before seeding the admin user.');
        }

        AdminUser::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'role' => AdminUser::ROLE_SUPER_ADMIN,
                'status' => AdminUser::STATUS_ACTIVE,
            ]
        );
    }
}
