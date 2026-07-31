<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SkinLabTest extends TestCase
{
    protected function setUp(): void
    {
        invoicing_test_install_mocks();
        initializeDatabase();
        require_once dirname(__DIR__, 2) . '/public/includes/skin-lab-env.php';
    }

    public function testNormalizeAndEffectiveSlug(): void
    {
        $this->assertSame(['hey', 'ledger', 'brutalist', 'obsidian'], invSkinAvailableSlugs());
        $this->assertNull(invSkinNormalizeSlug('nope'));
        $this->assertSame('hey', invSkinNormalizeSlug('HEY'));
        $this->assertTrue(invSkinUsesLightNav('hey'));
        $this->assertFalse(invSkinUsesLightNav('obsidian'));
        $this->assertSame('light', invSkinBootstrapTheme('ledger'));
        $this->assertSame('dark', invSkinBootstrapTheme('obsidian'));

        $this->assertFalse(invSkinShouldShowCompBar());
        $this->assertSame('HEY Bold', invSkinLabels()['hey']);

        // Clear site default → master falls back to hey (Tasks/CRM default).
        $db = getDbConnection();
        $db->exec("DELETE FROM config WHERE key = 'default_skin_slug'");
        $this->assertSame('hey', invSkinMasterSlug());

        set_config('default_skin_slug', 'brutalist');
        $this->assertSame('brutalist', invSkinMasterSlug());
        $this->assertSame('brutalist', invSkinEffectiveSlug(null));

        $_GET['preview_skin'] = 'hey';
        $this->assertSame('hey', invSkinEffectiveSlug(null));
        unset($_GET['preview_skin']);

        $uid = (int) $db->querySingle("SELECT id FROM admin_users WHERE username = 'admin'");
        $this->assertGreaterThan(0, $uid);
        $saved = invSkinSaveUserPreference($uid, 'ledger');
        $this->assertTrue($saved['success']);
        $row = ['skin_slug' => 'ledger'];
        $this->assertSame('ledger', invSkinEffectiveSlug($row));

        $cleared = invSkinSaveUserPreference($uid, null);
        $this->assertTrue($cleared['success']);
        $this->assertNull($cleared['skin_slug']);

        $site = invSkinSaveSiteDefault('obsidian');
        $this->assertTrue($site['success']);
        $this->assertSame('obsidian', invSkinMasterSlug());

        $bad = invSkinSaveUserPreference($uid, 'invalid');
        $this->assertFalse($bad['success']);

        $href = invSkinStylesheetHref('hey');
        $this->assertStringContainsString('assets/skins/hey.css', $href);
    }
}
