<?php
declare(strict_types=1);

namespace App\Models;


use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 *  User for internal admin portal
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $role
 * @property string $status
 * @property Carbon $last_login_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AdminUser extends Authenticatable
{
    use Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_SUPPORT = 'support';
    public const ROLE_ANALYST = 'analyst';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'last_login_at'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime'
        ];
    }

    public static function roles(): array
    {
        return [
            self::ROLE_SUPER_ADMIN,
            self::ROLE_SUPPORT,
            self::ROLE_ANALYST
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_DISABLED
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDisabled(): bool
    {
        return $this->status === self::STATUS_DISABLED;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isSupport(): bool
    {
        return $this->role === self::ROLE_SUPPORT;
    }

    public function isAnalyst(): bool
    {
        return $this->role === self::ROLE_ANALYST;
    }
}
