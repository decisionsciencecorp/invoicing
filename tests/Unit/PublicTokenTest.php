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
}
