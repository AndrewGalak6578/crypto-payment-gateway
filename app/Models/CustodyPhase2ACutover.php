<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CustodyPhase2ACutover extends Model
{
    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    public const PHASE_KEY = 'internal_credit_shadow_v1';

    protected $table = 'custody_phase2a_cutovers';

    protected $primaryKey = 'phase_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'activated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
