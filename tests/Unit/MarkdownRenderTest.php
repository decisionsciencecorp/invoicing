<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MarkdownRenderTest extends TestCase
{
    public function testRendersHeadersBoldAndLists(): void
    {
        $html = dsc_markdown_to_html("## Hours summary\n\n**Total:** 12.5h\n\n- Line one\n- Line two");
        $this->assertStringContainsString('<h2>Hours summary</h2>', $html);
        $this->assertStringContainsString('<strong>Total:</strong>', $html);
        $this->assertStringContainsString('<li>Line one</li>', $html);
    }

    public function testRendersTablesLikeTasksDocs(): void
    {
        $md = "| Category | Hours |\n|----------|------:|\n| **Discovery** | **2.75** |\n";
        $html = dsc_markdown_to_html($md);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>Category</th>', $html);
        $this->assertStringContainsString('<strong>Discovery</strong>', $html);
    }

    public function testEscapesHtmlInMarkdown(): void
    {
        $html = dsc_markdown_to_html('<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAutoLinksBareUrls(): void
    {
        $html = dsc_markdown_to_html('See https://example.com/path for details.');
        $this->assertStringContainsString('href="https://example.com/path"', $html);
    }

    public function testNormalizesPoisonedLiteralNewlines(): void
    {
        $poisoned = '# Title\\n\\n| A | B |\\n|---|---|\\n| 1 | 2 |';
        $html = dsc_markdown_to_html($poisoned);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<h1>Title</h1>', $html);
    }
}
