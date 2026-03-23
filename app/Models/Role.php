<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;


/**
 * Role model representing user roles.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MerchantUser $merchantUsers
 * @property-read User $users
 * @property Collection<int, Capability> $capabilities
 */
class Role extends Model
{
    protected $fillable = ['slug', 'name', 'description'];



    public function users(): HasMany
    {
        return $this->hasMany(MerchantUser::class);
    }

    public function capabilities(): BelongsToMany
    {
        return $this->belongsToMany(Capability::class, 'capability_role');
    }
}
