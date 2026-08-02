<?php

declare(strict_types=1);

namespace Tests\Feature\Custody;

use App\Data\CustodyJournalTransactionData;
use App\Exceptions\CustodyAccountingException;
use App\Models\CustodyAccount;
use App\Services\Custody\CustodyAccountRepository;
use App\Services\Custody\CustodyJournalWriter;
use App\Services\Custody\Phase2AGate;
use Tests\TestCase;

final class CustodyFeatureGateTest extends TestCase
{
    public function test_all_custody_and_payout_gates_default_to_false(): void
    {
        foreach ([
            'custody.accounting_enabled',
            'custody.journal_writes_enabled',
            'custody.phase2a_shadow_internal_credits_enabled',
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

    public function test_actual_config_boundary_preserves_booleans_and_non_boolean_values_for_all_seven_gates(): void
    {
        $gates = [
            'CUSTODY_ACCOUNTING_ENABLED' => 'accounting_enabled',
            'CUSTODY_JOURNAL_WRITES_ENABLED' => 'journal_writes_enabled',
            'CUSTODY_PHASE2A_SHADOW_INTERNAL_CREDITS_ENABLED' => 'phase2a_shadow_internal_credits_enabled',
            'CUSTODY_INVOICE_ROUTING_ENABLED' => 'invoice_routing_enabled',
            'PAYOUT_REQUESTS_ENABLED' => 'payout_requests_enabled',
            'PAYOUT_AUTOMATIC_REQUESTS_ENABLED' => 'payout_automatic_requests_enabled',
            'PAYOUT_EXECUTION_ENABLED' => 'payout_execution_enabled',
        ];
        $invalidValues = [
            ['flase', 'flase'],
            ['off', 'off'],
            ['yes', 'yes'],
            ['1', '1'],
            ['"0"', '0'],
        ];

        foreach ($gates as $environmentKey => $configKey) {
            self::assertTrue($this->custodyConfigValue($environmentKey, $configKey, 'true'));
            self::assertFalse($this->custodyConfigValue($environmentKey, $configKey, 'false'));

            foreach ($invalidValues as [$environmentValue, $expectedValue]) {
                $actual = $this->custodyConfigValue($environmentKey, $configKey, $environmentValue);
                self::assertSame($expectedValue, $actual, "{$environmentKey}={$environmentValue}");

                config()->set("custody.{$configKey}", $actual);
                try {
                    app(Phase2AGate::class)->activationConfig();
                    self::fail("{$environmentKey}={$environmentValue} must fail closed.");
                } catch (CustodyAccountingException $e) {
                    self::assertStringContainsString('must be a Boolean', $e->getMessage());
                } finally {
                    config()->set("custody.{$configKey}", false);
                }
            }
        }
    }

    public function test_account_repository_and_journal_writer_require_literal_true_gates(): void
    {
        config()->set('custody.accounting_enabled', 'true');
        config()->set('custody.journal_writes_enabled', true);

        try {
            app(CustodyAccountRepository::class)->merchant(
                1,
                'btc',
                'bitcoin',
                CustodyAccount::CODE_MERCHANT_AVAILABLE,
            );
            self::fail('A truthy non-Boolean accounting gate must not create an account.');
        } catch (CustodyAccountingException $e) {
            self::assertSame('Custody accounting is disabled.', $e->getMessage());
        }

        config()->set('custody.accounting_enabled', true);
        config()->set('custody.journal_writes_enabled', 'true');

        try {
            app(CustodyJournalWriter::class)->post(new CustodyJournalTransactionData(
                idempotencyKey: 'custody:non-boolean-gate',
                eventType: 'non_boolean_gate_test',
                assetKey: 'btc',
                networkKey: 'bitcoin',
                postings: [],
            ));
            self::fail('A truthy non-Boolean journal gate must not permit a write.');
        } catch (CustodyAccountingException $e) {
            self::assertSame('Custody journal writes are disabled.', $e->getMessage());
        }
    }

    private function custodyConfigValue(
        string $environmentKey,
        string $configKey,
        string $environmentValue,
    ): mixed {
        $hadEnvironmentValue = array_key_exists($environmentKey, $_ENV);
        $previousEnvironmentValue = $_ENV[$environmentKey] ?? null;
        $hadServerValue = array_key_exists($environmentKey, $_SERVER);
        $previousServerValue = $_SERVER[$environmentKey] ?? null;
        $previousProcessValue = getenv($environmentKey);

        $_ENV[$environmentKey] = $environmentValue;
        $_SERVER[$environmentKey] = $environmentValue;
        putenv("{$environmentKey}={$environmentValue}");

        try {
            /** @var array<string, mixed> $custodyConfig */
            $custodyConfig = require base_path('config/custody.php');

            return $custodyConfig[$configKey];
        } finally {
            if ($hadEnvironmentValue) {
                $_ENV[$environmentKey] = $previousEnvironmentValue;
            } else {
                unset($_ENV[$environmentKey]);
            }

            if ($hadServerValue) {
                $_SERVER[$environmentKey] = $previousServerValue;
            } else {
                unset($_SERVER[$environmentKey]);
            }

            putenv($previousProcessValue === false
                ? $environmentKey
                : "{$environmentKey}={$previousProcessValue}");
        }
    }
}
