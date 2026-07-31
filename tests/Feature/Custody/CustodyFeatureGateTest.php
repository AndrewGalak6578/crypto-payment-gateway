<?php

declare(strict_types=1);

namespace Tests\Feature\Custody;

use App\Data\CustodyJournalTransactionData;
use App\Exceptions\CustodyAccountingException;
use App\Services\Custody\CustodyJournalWriter;
use Tests\TestCase;

final class CustodyFeatureGateTest extends TestCase
{
    public function test_all_custody_and_payout_gates_default_to_false(): void
    {
        foreach ([
            'custody.accounting_enabled',
            'custody.journal_writes_enabled',
            'custody.invoice_routing_enabled',
            'custody.payout_requests_enabled',
            'custody.payout_automatic_requests_enabled',
            'custody.payout_execution_enabled',
        ] as $key) {
            self::assertFalse(config($key), "{$key} must default to false.");
        }
    }

    public function test_explicit_writer_is_blocked_while_gates_are_off(): void
    {
        $this->expectException(CustodyAccountingException::class);
        $this->expectExceptionMessage('Custody journal writes are disabled.');

        app(CustodyJournalWriter::class)->post(new CustodyJournalTransactionData(
            idempotencyKey: 'custody:disabled',
            eventType: 'disabled_test',
            assetKey: 'btc',
            networkKey: 'bitcoin',
            postings: [],
        ));
    }
}
