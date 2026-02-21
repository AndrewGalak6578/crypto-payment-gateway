<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $table = 'invoices';

    protected $fillable = [
        'merchant_id','public_id','external_id',
        'status','coin','pay_address',
        'amount_coin','expected_usd','rate_usd',
        'expires_at','fixated_at','paid_at','monitor_until',
        'first_txid','first_amount_coin',
        'received_conf_coin','received_all_coin',
        'paid_usd','fee_usd','merchant_payout_usd',
        'forward_status','metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'fixated_at' => 'datetime',
        'paid_at' => 'datetime',
        'monitor_until' => 'datetime',
        'metadata' => 'array'
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
