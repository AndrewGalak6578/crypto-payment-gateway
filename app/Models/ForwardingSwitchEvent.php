<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ForwardingSwitchEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'enabled',
        'actor',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
