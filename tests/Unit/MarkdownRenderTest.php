<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MarkdownRenderTest extends TestCase
{
    public function testRendersHeadersAndBold(): void
    {
        $html = dsc_markdown_to_html("## Hours summary\n\n**Total:** 12.5h\n\n- Line one\n- Line two");
        $this->assertStringContainsString('<h3>Hours summary</h3>', $html);
        $this->assertStringContainsString('<strong>Total:</strong>', $html);
        $this->assertStringContainsString('<li>Line one</li>', $html);
    }

    public function testEscapesHtmlInMarkdown(): void
    {
        $html = dsc_markdown_to_html('<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
