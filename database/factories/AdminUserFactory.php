<?php
declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AdminUser>
 */
class AdminUserFactory extends Factory
{
    protected $model = \App\Models\AdminUser::class;
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => AdminUser::ROLE_ANALYST,
            'status' => AdminUser::STATUS_ACTIVE,
            'last_login_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn() => [
            'role' => AdminUser::ROLE_SUPER_ADMIN,
        ]);
    }

    public function support(): static
    {
        return $this->state(fn() => [
            'role' => AdminUser::ROLE_SUPPORT,
        ]);
    }

    public function analyst(): static
    {
        return $this->state(fn() => [
            'role' => AdminUser::ROLE_ANALYST,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn() => [
            'status' => AdminUser::STATUS_DISABLED
        ]);
    }
}
