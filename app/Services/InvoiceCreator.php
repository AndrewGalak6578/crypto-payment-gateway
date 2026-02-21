<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Merchant;
use App\Support\Coin;
use Illuminate\Support\Str;

class InvoiceCreator
{
    public function create(Merchant $merchant, array $data): Invoice
    {
        $coin = Coin::normalize($data['coin'] ?? 'dash');
        $externalId = $data['external_id'] ?? null;

        if ($externalId) {
            $existing = Invoice::where('merchant_id', $merchant->id)
                ->where('external_id', $externalId)
                ->first();

            if ($existing) return $existing;
        }

        $amountUsd = round((float)$data['amount_usd'], 3);

        $rateUsd = match ($coin) {
            'btc' => 60000.0,
            'ltc' => 80.0,
            default => 30.0,
        };

        $decimals = $coin === 'dash' ? 3 : 8;
        $amountCoin = round($amountUsd / max($rateUsd, 1e-8), $decimals);

        $expiresMin = (int)config('payments.invoice_ttl_minutes', 60);
        $deadline = now('UTC')->addMinutes($expiresMin);

        $invoice = Invoice::create([
            'merchant_id' => $merchant->id,
            'public_id' => Str::lower(Str::random(16)),
            'external_id' => $externalId,
            'status' => 'pending',
            'coin' => $coin,
            'pay_address' => 'mock_' . Str::lower(Str::random(24)), // mock
            'amount_coin' => $amountCoin,
            'expected_usd' => $amountUsd,
            'rate_usd' => $rateUsd,
            'expires_at' => $deadline,
            'metadata' => $data['metadata'] ?? null,
        ]);

        return $invoice;
    }
}
