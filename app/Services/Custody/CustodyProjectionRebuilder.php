<?php

declare(strict_types=1);

namespace App\Services\Custody;

use App\Exceptions\CustodyAccountingException;
use Illuminate\Support\Facades\DB;

final readonly class CustodyProjectionRebuilder
{
    public function __construct(
        private CustodyProjectionVerifier $verifier,
        private CustodyProjectionCalculator $calculator,
    ) {}

    /**
     * @return array{
     *   mode: string,
     *   before: array<string, mixed>,
     *   after: array<string, mixed>
     * }
     */
    public function rebuild(bool $write, ?int $merchantId = null, ?string $assetKey = null): array
    {
        $before = $this->verifier->verify($merchantId, $assetKey);

        if (! $write) {
            return [
                'mode' => 'dry-run',
                'before' => $before,
                'after' => $before,
            ];
        }

        DB::transaction(function () use ($merchantId, $assetKey): void {
            $accounts = $this->verifier
                ->accountQuery($merchantId, $assetKey)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $expected = $this->calculator->calculate($accounts);
            $now = now('UTC');

            foreach ($accounts as $account) {
                DB::table('custody_account_balances')->insertOrIgnore([
                    'account_id' => $account->id,
                    'balance' => '0',
                    'last_journal_entry_id' => null,
                    'revision' => 0,
                    'rebuilt_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $locked = DB::table('custody_account_balances')
                ->whereIn('account_id', $accounts->modelKeys())
                ->orderBy('account_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('account_id');

            if ($locked->count() !== $accounts->count()) {
                throw new CustodyAccountingException('Projection rebuild could not lock every account balance.');
            }

            foreach ($accounts as $account) {
                $state = $expected[$account->id];
                DB::table('custody_account_balances')
                    ->where('account_id', $account->id)
                    ->update([
                        'balance' => $state['balance'],
                        'revision' => $state['revision'],
                        'last_journal_entry_id' => $state['last_journal_entry_id'],
                        'rebuilt_at' => $now,
                        'updated_at' => $now,
                    ]);
            }
        }, 5);

        return [
            'mode' => 'write',
            'before' => $before,
            'after' => $this->verifier->verify($merchantId, $assetKey),
        ];
    }
}
