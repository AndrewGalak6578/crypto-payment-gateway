<?php
declare(strict_types=1);

namespace App\Services\PaymentAddresses\Evm;

use App\Contracts\DerivationIndexStoreInterface;
use App\Models\Merchant;
use Illuminate\Support\Facades\DB;

class DatabaseDerivationIndexStore implements DerivationIndexStoreInterface
{

    public function reserveNext(Merchant $merchant, string $networkKey, string $keyRef): int
    {
        return DB::transaction(function () use ($merchant, $networkKey, $keyRef): int {
            $networkKey = strtolower($networkKey);

            $row = DB::table('payment_address_sequences')
                ->where('merchant_id', $merchant->id)
                ->where('network_key', $networkKey)
                ->where('key_ref', $keyRef)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('payment_address_sequences')->insert([
                    'merchant_id' => $merchant->id,
                    'network_key' => $networkKey,
                    'key_ref' => $keyRef,
                    'last_issued_index' => 0,
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);

                return 0;
            }

            $nextIndex = (int) $row->last_issued_index + 1;

            DB::table('payment_address_sequences')
                ->where('id', $row->id)
                ->update([
                    'last_issued_index' => $nextIndex,
                    'updated_at' => now('UTC'),
                ]);
            return $nextIndex;
        });
    }
}
