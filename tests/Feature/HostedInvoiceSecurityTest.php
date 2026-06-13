<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsDomainData;
use Tests\TestCase;

final class HostedInvoiceSecurityTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_public_status_endpoint_returns_snapshot_without_forcing_refresh(): void
    {
        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'status' => 'pending',
            'received_conf_coin' => 0,
            'received_all_coin' => 0,
        ]);

        $response = $this->getJson("/i/{$invoice->public_id}/status?refresh=1");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.public_id', $invoice->public_id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.received_conf_coin', '0.00000000');
    }
}
