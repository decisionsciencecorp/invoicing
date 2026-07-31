<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FunctionsCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        invoicing_test_install_mocks();
        initializeDatabase();
    }

    public function testWebBasePathVariants(): void
    {
        putenv('INVOICING_WEB_BASE');
        $_SERVER['SCRIPT_NAME'] = '/subdir/admin/companies.php';
        $this->assertSame('/subdir', dsc_invoicing_web_base_path());
        $_SERVER['SCRIPT_NAME'] = '/subdir/api/health.php';
        $this->assertSame('/subdir', dsc_invoicing_web_base_path());
        $_SERVER['SCRIPT_NAME'] = '/subdir/index.php';
        $this->assertSame('/subdir', dsc_invoicing_web_base_path());
        unset($_SERVER['SCRIPT_NAME']);
        $_SERVER['SCRIPT_FILENAME'] = '/var/www/public/admin/login.php';
        $_SERVER['DOCUMENT_ROOT'] = '/var/www';
        $this->assertSame('/public/admin', dsc_invoicing_web_base_path());
        unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['DOCUMENT_ROOT']);
        $_SERVER['REQUEST_URI'] = '/app/admin/login.php?x=1';
        $this->assertSame('/app', dsc_invoicing_web_base_path());
        unset($_SERVER['REQUEST_URI']);

        set_config('web_base_path', '/from-config');
        $this->assertSame('/from-config', dsc_invoicing_web_base_path());
        set_config('web_base_path', '');
    }

    public function testGetApiKeySources(): void
    {
        $key = createApiKey('gapik');
        $_SERVER['HTTP_X_API_KEY'] = $key;
        $this->assertSame($key, getApiKey());
        unset($_SERVER['HTTP_X_API_KEY']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
        $this->assertSame($key, getApiKey());
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
        $this->assertSame('Bearer ' . $key, dsc_invoicing_authorization_header());
        unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $_GET['api_key'] = $key;
        $this->assertSame($key, getApiKey());
        unset($_GET['api_key']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['api_key'] = $key;
        $this->assertSame($key, getApiKey());
        unset($_POST['api_key'], $_SERVER['REQUEST_METHOD']);
        $this->assertNull(getApiKey());
    }

    public function testRateLimitWindowReset(): void
    {
        $db = getDbConnection();
        $rk = 'rl:reset:' . uniqid();
        $this->assertTrue(checkRateLimit($rk, 5, 60));
        $up = $db->prepare('UPDATE api_rate_limits SET window_start = :w WHERE rate_key = :k');
        $up->bindValue(':w', time() - 120, SQLITE3_INTEGER);
        $up->bindValue(':k', $rk, SQLITE3_TEXT);
        $up->execute();
        $this->assertTrue(checkRateLimit($rk, 5, 60));
    }

    public function testSquareConfigFromEnvFile(): void
    {
        $root = dirname(__DIR__, 2);
        $envFile = $root . '/.env.sandbox';
        $had = is_file($envFile);
        $prev = $had ? file_get_contents($envFile) : null;
        file_put_contents($envFile, "SQUARE_ACCESS_TOKEN=fromfile\nSQUARE_ENVIRONMENT=sandbox\nSQUARE_LOCATION_ID=LOCFILE\n");
        putenv('INVOICING_SQUARE_SKIP_ENV_FILE');
        putenv('SQUARE_ACCESS_TOKEN');
        putenv('SQUARE_LOCATION_ID');
        square_config_reset();
        // clear getenv leftovers for token
        putenv('SQUARE_ACCESS_TOKEN=');
        putenv('SQUARE_LOCATION_ID=');
        $cfg = dsc_invoicing_square_config();
        $this->assertNotEmpty($cfg['access_token']);
        square_config_reset();
        putenv('INVOICING_SQUARE_SKIP_ENV_FILE=1');
        putenv('SQUARE_ACCESS_TOKEN=sandbox-test-token');
        putenv('SQUARE_LOCATION_ID=LOC_TEST');
        if ($had && $prev !== null) {
            file_put_contents($envFile, $prev);
        } else {
            @unlink($envFile);
        }
    }

    public function testLoginFailureAndTasksConfigFromDb(): void
    {
        $bad = login('admin', 'definitely-wrong-password');
        $this->assertFalse($bad['success']);
        set_config('tasks_psf_project_id', '9');
        $this->assertSame(9, dsc_tasks_psf_project_id());
        set_config('tasks_psf_project_id', '');
        putenv('TASKS_DSC_BASE_URL=');
        putenv('TASKS_DSC_OTTOVERNAL_API_KEY=');
        set_config('tasks_dsc_base_url', 'https://tasks.from.db');
        set_config('tasks_dsc_api_key', 'dbkey');
        $cfg = dsc_tasks_api_config();
        $this->assertSame('https://tasks.from.db', $cfg['base_url']);
        putenv('TASKS_DSC_BASE_URL=https://tasks.example.test');
        putenv('TASKS_DSC_OTTOVERNAL_API_KEY=tasks-test-key');
        // Unconfigured list short-circuits to []
        putenv('TASKS_DSC_BASE_URL=');
        putenv('TASKS_DSC_OTTOVERNAL_API_KEY=');
        set_config('tasks_dsc_base_url', '');
        set_config('tasks_dsc_api_key', '');
        $this->assertSame([], dsc_tasks_list_accounting_documents());
        putenv('TASKS_DSC_BASE_URL=https://tasks.example.test');
        putenv('TASKS_DSC_OTTOVERNAL_API_KEY=tasks-test-key');
    }
}
