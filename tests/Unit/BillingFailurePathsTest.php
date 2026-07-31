<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BillingFailurePathsTest extends TestCase
{
    protected function setUp(): void
    {
        invoicing_test_install_mocks();
        initializeDatabase();
    }

    public function testSquareCreatePublishErrors(): void
    {
        putenv('SQUARE_LOCATION_ID=');
        set_config('square_location_id', '');
        square_config_reset();
        $err = dsc_billing_square_create_publish_invoice('CUST', [], '2026-01-01', 't', 'd', 'n', 'seed');
        $this->assertFalse($err['ok']);

        putenv('SQUARE_LOCATION_ID=LOC_TEST');
        set_config('square_location_id', 'LOC_TEST');
        square_config_reset();
        $empty = dsc_billing_square_create_publish_invoice('CUST', [], '2026-01-01', 't', 'd', 'n', 'seed2');
        $this->assertFalse($empty['ok']);

        $GLOBALS['_dsc_square_mock_handler'] = static function (string $method, string $path, ?array $body): array {
            if ($path === '/orders') {
                return ['ok' => false, 'error' => 'order boom'];
            }
            return invoicing_test_default_square_mock($method, $path, $body);
        };
        $failOrder = dsc_billing_square_create_publish_invoice(
            'CUST',
            [['name' => 'x', 'quantity' => '1', 'base_price_money' => ['amount' => 100, 'currency' => 'USD']]],
            '2026-01-01',
            't',
            'd',
            'n',
            'seed3'
        );
        $this->assertFalse($failOrder['ok']);

        $GLOBALS['_dsc_square_mock_handler'] = static function (string $method, string $path, ?array $body): array {
            if ($path === '/orders') {
                return ['ok' => true, 'data' => ['order' => []]];
            }
            return invoicing_test_default_square_mock($method, $path, $body);
        };
        $noOrderId = dsc_billing_square_create_publish_invoice(
            'CUST',
            [['name' => 'x', 'quantity' => '1', 'base_price_money' => ['amount' => 100, 'currency' => 'USD']]],
            '2026-01-01',
            't',
            'd',
            'n',
            'seed4'
        );
        $this->assertFalse($noOrderId['ok']);

        $GLOBALS['_dsc_square_mock_handler'] = static function (string $method, string $path, ?array $body): array {
            if ($path === '/invoices') {
                return ['ok' => false, 'error' => 'inv boom'];
            }
            return invoicing_test_default_square_mock($method, $path, $body);
        };
        $failInv = dsc_billing_square_create_publish_invoice(
            'CUST',
            [['name' => 'x', 'quantity' => '1', 'base_price_money' => ['amount' => 100, 'currency' => 'USD']]],
            '2026-01-01',
            't',
            'd',
            'n',
            'seed5'
        );
        $this->assertFalse($failInv['ok']);

        $GLOBALS['_dsc_square_mock_handler'] = static function (string $method, string $path, ?array $body): array {
            if ($path === '/invoices') {
                return ['ok' => true, 'data' => ['invoice' => []]];
            }
            return invoicing_test_default_square_mock($method, $path, $body);
        };
        $noInvId = dsc_billing_square_create_publish_invoice(
            'CUST',
            [['name' => 'x', 'quantity' => '1', 'base_price_money' => ['amount' => 100, 'currency' => 'USD']]],
            '2026-01-01',
            't',
            'd',
            'n',
            'seed6'
        );
        $this->assertFalse($noInvId['ok']);

        $GLOBALS['_dsc_square_mock_handler'] = static function (string $method, string $path, ?array $body): array {
            if (str_contains($path, '/publish')) {
                return ['ok' => false, 'error' => 'pub boom'];
            }
            return invoicing_test_default_square_mock($method, $path, $body);
        };
        $failPub = dsc_billing_square_create_publish_invoice(
            'CUST',
            [['name' => 'x', 'quantity' => '1', 'base_price_money' => ['amount' => 100, 'currency' => 'USD']]],
            '2026-01-01',
            't',
            'd',
            'n',
            'seed7'
        );
        $this->assertFalse($failPub['ok']);

        invoicing_test_install_mocks();
    }

    public function testPublishFailuresAndRefreshErrors(): void
    {
        $db = getDbConnection();
        $seed = invoicing_test_seed_company_engagement($db, 0, 0, 'flat_tier', 0, 0);
        $zero = dsc_billing_publish_combined_invoice($db, $seed['engagement_id'], '2026-07', null, 'tier1');
        $this->assertFalse($zero['ok']);

        $seed2 = invoicing_test_seed_company_engagement($db, 0, 0, 'flat_tier', 1000, 2000);
        putenv('SQUARE_LOCATION_ID=');
        set_config('square_location_id', '');
        square_config_reset();
        $noLoc = dsc_billing_publish_combined_invoice($db, $seed2['engagement_id'], '2026-07', null, 'tier1');
        $this->assertFalse($noLoc['ok']);
        putenv('SQUARE_LOCATION_ID=LOC_TEST');
        set_config('square_location_id', 'LOC_TEST');
        square_config_reset();

        $badDoc = dsc_billing_publish_combined_invoice($db, $seed2['engagement_id'], '2026-07', 999001, 'tier1');
        $this->assertFalse($badDoc['ok']);

        $this->assertFalse(dsc_billing_refresh_square_invoice_component('')['ok']);
        $GLOBALS['_dsc_square_mock_handler'] = static function (): array {
            return ['ok' => false, 'error' => 'get fail'];
        };
        $this->assertFalse(dsc_billing_refresh_square_invoice_component('inv:x')['ok']);
        invoicing_test_install_mocks();
        $GLOBALS['_dsc_square_mock_handler'] = static function (): array {
            return ['ok' => true, 'data' => []];
        };
        $this->assertFalse(dsc_billing_refresh_square_invoice_component('inv:x')['ok']);
        invoicing_test_install_mocks();

        $this->assertFalse(dsc_billing_refresh_outbound_payment_status($db, 0)['ok']);
        $this->assertFalse(dsc_billing_refresh_outbound_payment_status($db, 99999)['ok']);
        $this->assertFalse(dsc_billing_attach_tasks_document_to_outbound($db, 0, 1)['ok']);
        $this->assertFalse(dsc_billing_attach_tasks_document_to_outbound($db, 99999, 1)['ok']);
    }

    public function testClientPageUrlFallbacks(): void
    {
        $u2 = dsc_billing_client_page_url([
            'public_url' => 'http://localhost/invoice.php?t=abc',
            'public_token' => 'abcdabcdabcdabcdabcdabcdabcdabcd',
        ]);
        $this->assertStringContainsString('invoice.php?t=', $u2);
        $u3 = dsc_billing_client_page_url([
            'public_url' => '',
            'public_token' => 'abcdabcdabcdabcdabcdabcdabcdabcd',
        ]);
        $this->assertStringContainsString('invoice.php?t=abcdabcdabcdabcdabcdabcdabcdabcd', $u3);
    }
}
