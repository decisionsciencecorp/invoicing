<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InvoiceUrlTest extends TestCase
{
    public function testResolveSiteUrlFromRequestHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'invoicing.decisionsciencecorp.com';
        $_SERVER['HTTPS'] = 'on';
        putenv('SITE_URL');
        $this->assertSame(
            'https://invoicing.decisionsciencecorp.com',
            dsc_invoicing_resolve_site_url()
        );
    }

    public function testClientPageUrlPrefersStoredProductionUrl(): void
    {
        initializeDatabase();
        $row = [
            'public_url' => 'https://invoicing.decisionsciencecorp.com/invoice.php?t=abc',
            'public_token' => 'abc',
        ];
        $this->assertSame(
            'https://invoicing.decisionsciencecorp.com/invoice.php?t=abc',
            dsc_billing_client_page_url($row)
        );
    }

    public function testCanonicalUrlNeverLocalhostOnProdHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'invoicing.decisionsciencecorp.com';
        $_SERVER['HTTPS'] = 'on';
        $url = dsc_billing_canonical_invoice_url('deadbeefdeadbeefdeadbeefdeadbeef');
        $this->assertStringStartsWith('https://invoicing.decisionsciencecorp.com', $url);
        $this->assertStringNotContainsString('localhost', $url);
    }
}
