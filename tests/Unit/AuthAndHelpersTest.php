<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuthAndHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        invoicing_test_install_mocks();
        initializeDatabase();
        $_SESSION = [];
    }

    public function testLoginChangePasswordAndAdminUserOps(): void
    {
        $ok = login('admin', 'admin');
        $this->assertTrue($ok['success']);
        $this->assertTrue(isLoggedIn());
        $user = getCurrentUser();
        $this->assertNotNull($user);
        $uid = (int) $user['id'];

        $bad = dsc_invoicing_change_password($uid, 'wrong', 'newpassword1');
        $this->assertFalse($bad['success']);

        $short = dsc_invoicing_change_password($uid, 'admin', 'short');
        $this->assertFalse($short['success']);

        $chg = dsc_invoicing_change_password($uid, 'admin', 'newpassword1');
        $this->assertTrue($chg['success']);
        $this->assertTrue(login('admin', 'newpassword1')['success']);

        $created = dsc_invoicing_admin_create_user('peer', 'peerpass1');
        $this->assertTrue($created['success']);
        $peerId = (int) $created['id'];
        $dup = dsc_invoicing_admin_create_user('peer', 'peerpass1');
        $this->assertFalse($dup['success']);

        $set = dsc_invoicing_admin_set_user_password($peerId, 'peerpass2');
        $this->assertTrue($set['success']);
        $this->assertFalse(dsc_invoicing_admin_set_user_password(99999, 'peerpass2')['success']);
        $this->assertFalse(dsc_invoicing_admin_set_user_password($peerId, 'short')['success']);
        $this->assertFalse(dsc_invoicing_admin_create_user('', 'peerpass1')['success']);

        logout();
        $this->assertFalse(isLoggedIn());
    }

    public function testCsrfHelpers(): void
    {
        $t = getCsrfToken();
        $this->assertNotSame('', $t);
        $this->assertTrue(verifyCsrfToken($t));
        $this->assertFalse(verifyCsrfToken('bad'));
        $field = csrfField();
        $this->assertStringContainsString('csrf_token', $field);
    }

    public function testApiKeyAndRateLimit(): void
    {
        $key = createApiKey('unit');
        $this->assertNotSame('', $key);
        $this->assertTrue(validateApiKey($key));
        $this->assertFalse(validateApiKey('nope'));
        $this->assertFalse(validateApiKey(null));

        $_SERVER['HTTP_X_API_KEY'] = $key;
        $this->assertSame($key, dsc_invoicing_resolve_api_key());
        unset($_SERVER['HTTP_X_API_KEY']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
        $this->assertSame($key, dsc_invoicing_resolve_api_key());
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_GET['api_key'] = $key;
        $this->assertSame($key, dsc_invoicing_resolve_api_key());
        unset($_GET['api_key']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertSame($key, dsc_invoicing_resolve_api_key(['api_key' => $key]));
        unset($_SERVER['REQUEST_METHOD']);

        $rk = 'rl:unit:' . uniqid('', true);
        $this->assertTrue(checkRateLimit($rk, 2, 60));
        $this->assertTrue(checkRateLimit($rk, 2, 60));
        $this->assertFalse(checkRateLimit($rk, 2, 60));
    }

    public function testHrefAndBasePath(): void
    {
        putenv('INVOICING_WEB_BASE=/inv');
        $this->assertSame('/inv/admin/login.php', dsc_invoicing_href('admin/login.php'));
        putenv('INVOICING_WEB_BASE');
        $_SERVER['SCRIPT_NAME'] = '/admin/login.php';
        $this->assertSame('/admin/login.php', dsc_invoicing_href('admin/login.php'));
        unset($_SERVER['SCRIPT_NAME']);

        $base = dsc_invoicing_public_base_url();
        $this->assertStringContainsString('invoicing.decisionsciencecorp.com', $base);
        app_log('info', 'phpunit log line');
        $this->assertTrue(checkDatabaseHealth()['ok']);
    }

    public function testSafeAdminReturn(): void
    {
        putenv('INVOICING_WEB_BASE');
        $this->assertSame('/admin/index.php', dsc_invoicing_safe_admin_return(''));
        $this->assertSame('/admin/invoices.php?tab=list', dsc_invoicing_safe_admin_return('/admin/invoices.php?tab=list'));
        $this->assertSame('/admin/index.php', dsc_invoicing_safe_admin_return('https://evil.example/admin/invoices.php'));
        $this->assertSame('/admin/index.php', dsc_invoicing_safe_admin_return('/admin/login.php'));
        $this->assertSame('/admin/index.php', dsc_invoicing_safe_admin_return('../etc/passwd'));
    }

    public function testMarkdownAndSquareConfigured(): void
    {
        $this->assertTrue(square_is_configured());
        $html = dsc_markdown_to_html("**bold**\n\n- item");
        $this->assertStringContainsString('bold', $html);
        $poison = dsc_markdown_normalize_storage("line1\\nline2\\u0041");
        $this->assertStringContainsString("\n", $poison);
    }
}
