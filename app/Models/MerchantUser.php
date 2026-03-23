<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Merchant user
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $status
 * @property Carbon|null $last_login_at
 * @property Merchant $merchant
 * @property Role $role
 */
class MerchantUser extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'merchant_id',
        'name',
        'email',
        'password',
        'status',
        'last_login_at',
        'role_id'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'password' => 'hashed'
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
