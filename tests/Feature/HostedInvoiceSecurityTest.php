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

    public function test_hosted_page_bootstraps_safe_redirect_metadata(): void
    {
        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'metadata' => [
                'redirects' => [
                    'success_url' => 'https://merchant.example/orders/123/success',
                    'return_url' => 'https://merchant.example/orders/123',
                ],
            ],
        ]);

        $response = $this->get("/i/{$invoice->public_id}");

        $response->assertOk()
            ->assertSee('https:\/\/merchant.example\/orders\/123\/success', false)
            ->assertSee('https:\/\/merchant.example\/orders\/123', false);
    }

    public function test_hosted_page_ignores_unsafe_redirect_metadata(): void
    {
        $merchant = $this->createMerchant();
        $invoice = $this->createInvoice($merchant, [
            'metadata' => [
                'redirects' => [
                    'success_url' => 'javascript:alert(1)',
                    'return_url' => 'https://user:pass@merchant.example/orders/123',
                ],
            ],
        ]);

        $response = $this->get("/i/{$invoice->public_id}");

        $response->assertOk()
            ->assertDontSee('javascript:alert', false)
            ->assertDontSee('user:pass@merchant.example', false);
    }
}
