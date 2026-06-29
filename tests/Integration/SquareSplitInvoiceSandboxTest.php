<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @group square-sandbox
 */
final class SquareSplitInvoiceSandboxTest extends TestCase
{
    protected function setUp(): void
    {
        initializeDatabase();
        if (!square_is_configured()) {
            $this->markTestSkipped('Square sandbox credentials not configured (SQUARE_ACCESS_TOKEN or config table).');
        }
        $cfg = dsc_invoicing_square_config();
        if (($cfg['environment'] ?? 'sandbox') !== 'sandbox') {
            $this->markTestSkipped('Refusing Square mutation test unless environment is sandbox.');
        }
    }

    public function testCreatesRetainerInvoiceWithPublicUrl(): void
    {
        initializeDatabase();
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 5, 10000);
        set_config('square_access_token', (string) (dsc_invoicing_square_config()['access_token'] ?? ''));
        set_config('square_environment', 'sandbox');
        if (empty(dsc_invoicing_square_config()['location_id'])) {
            $this->markTestSkipped('Square location_id not configured.');
        }

        $cust = dsc_invoicing_square_ensure_company_customer($db, $seed['company_id']);
        $this->assertTrue($cust['ok'], $cust['error'] ?? 'customer failed');
        $customerId = (string) ($cust['customer_id'] ?? '');
        $this->assertNotSame('', $customerId);

        $due = dsc_billing_due_dates_for_publish();
        $result = dsc_billing_square_create_publish_invoice(
            $customerId,
            [[
                'name' => 'PHPUnit retainer',
                'quantity' => '1',
                'base_price_money' => ['amount' => 50000, 'currency' => 'USD'],
            ]],
            $due['retainer_due_date'],
            'PHPUnit retainer title',
            'Sandbox test invoice',
            'PHPU' . substr((string) time(), -6),
            'phpunit-retainer-' . uniqid('', true)
        );

        $this->assertTrue($result['ok'], $result['error'] ?? 'invoice failed');
        $this->assertNotEmpty($result['invoice_id']);
        $this->assertStringStartsWith('inv:', (string) $result['invoice_id']);
        $this->assertNotEmpty($result['public_url']);
        $this->assertStringContainsString('square', strtolower((string) $result['public_url']));
    }
}
