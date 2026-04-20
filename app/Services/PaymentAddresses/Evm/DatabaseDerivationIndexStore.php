<?php
declare(strict_types=1);

namespace App\Services\PaymentAddresses\Evm;

use App\Contracts\DerivationIndexStoreInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DatabaseDerivationIndexStore implements DerivationIndexStoreInterface
{
    public function reserveNext(string $family, string $networkKey, string $keyRef): int
    {
        return DB::transaction(function () use ($family, $networkKey, $keyRef): int {
            $family = strtolower($family);
            $networkKey = strtolower($networkKey);

            $row = DB::table('payment_address_cursors')
                ->where('family', $family)
                ->where('network_key', $networkKey)
                ->where('key_ref', $keyRef)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                try {
                    DB::table('payment_address_cursors')->insert([
                        'family' => $family,
                        'network_key' => $networkKey,
                        'key_ref' => $keyRef,
                        'next_index' => 1,
                        'created_at' => now('UTC'),
                        'updated_at' => now('UTC'),
                    ]);

                    return 0;
                } catch (QueryException $exception) {
                    if ($this->isUniqueConstraintViolation($exception) === false) {
                        throw $exception;
                    }

                    $row = DB::table('payment_address_cursors')
                        ->where('family', $family)
                        ->where('network_key', $networkKey)
                        ->where('key_ref', $keyRef)
                        ->lockForUpdate()
                        ->first();

                    if ($row === null) {
                        throw $exception;
                    }
                }
            }

            $currentIndex = (int)$row->next_index;

            DB::table('payment_address_cursors')
                ->where('id', $row->id)
                ->update([
                    'next_index' => $currentIndex + 1,
                    'updated_at' => now('UTC'),
                ]);

            return $currentIndex;
        });
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string)($exception->errorInfo[0] ?? '');

        return $sqlState === '23000' || $sqlState === '23505';
    }
}
