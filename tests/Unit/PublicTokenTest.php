<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PublicTokenTest extends TestCase
{
    public function testPublicTokenFormat(): void
    {
        $token = dsc_billing_generate_public_token();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
    }

    public function testCanonicalUrlUsesSiteUrl(): void
    {
        $url = dsc_billing_canonical_invoice_url('abcdabcdabcdabcdabcdabcdabcdabcd');
        $this->assertStringStartsWith('https://invoicing.decisionsciencecorp.com', $url);
        $this->assertStringContainsString('invoice.php?t=abcdabcdabcdabcdabcdabcdabcdabcd', $url);
    }

    public function testGetOutboundByTokenRejectsInvalid(): void
    {
        initializeDatabase();
        $db = getDbConnection();
        $this->assertNull(dsc_billing_get_outbound_by_public_token($db, 'not-a-token'));
        $this->assertNull(dsc_billing_get_outbound_by_public_token($db, 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz'));
    }

    public function testOutboundNeedsPaymentRefreshSkipsFullyPaid(): void
    {
        $this->assertFalse(dsc_billing_outbound_needs_payment_refresh([
            'payment_status' => 'paid',
            'retainer_payment_status' => 'paid',
            'overage_amount_cents' => 0,
        ]));
        $this->assertTrue(dsc_billing_outbound_needs_payment_refresh([
            'payment_status' => 'published',
            'retainer_payment_status' => 'published',
            'overage_amount_cents' => 0,
        ]));
        $this->assertTrue(dsc_billing_outbound_needs_payment_refresh([
            'payment_status' => 'partial',
            'retainer_payment_status' => 'paid',
            'overage_amount_cents' => 10000,
            'square_overage_invoice_id' => 'inv:overage',
            'overage_payment_status' => 'published',
        ]));
        $this->assertFalse(dsc_billing_outbound_needs_payment_refresh([
            'payment_status' => 'paid',
            'retainer_payment_status' => 'paid',
            'overage_amount_cents' => 10000,
            'square_overage_invoice_id' => 'inv:overage',
            'overage_payment_status' => 'paid',
        ]));
    }
}
