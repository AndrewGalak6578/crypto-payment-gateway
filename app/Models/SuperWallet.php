<?php

namespace App\Models;

use App\Support\Coin;
use Illuminate\Database\Eloquent\Model;

class SuperWallet extends Model
{
    protected $table = 'super_wallets';

    protected $fillable = [
        'coin', 'wallet', 'fee_rate'
    ];

    public static function forCoin(string $coin): ?self
    {
        $coin = Coin::normalize($coin);
        return static::where('coin', $coin)->first();
    }
}
