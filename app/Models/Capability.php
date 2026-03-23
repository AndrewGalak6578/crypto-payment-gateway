<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Represents a capability that can be assigned to roles.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $description
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Capability extends Model
{
    protected $fillable = ['code', 'name', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'capability_role');
    }
}
