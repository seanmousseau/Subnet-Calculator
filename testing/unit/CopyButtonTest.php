<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Subnet-Calculator/includes/functions-util.php';

final class CopyButtonTest extends TestCase
{
    public function testEscapesValueAndLabel(): void
    {
        $html = copy_button('<script>', 'Copy <X>');
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Copy &lt;X&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testProducesCanonicalSubnetCopyMarkup(): void
    {
        $html = copy_button('10.0.0.1', 'Copy 10.0.0.1');
        $this->assertStringContainsString('class="subnet-copy"', $html);
        $this->assertStringContainsString('data-copy="10.0.0.1"', $html);
        $this->assertStringContainsString('aria-label="Copy 10.0.0.1"', $html);
        $this->assertStringContainsString('type="button"', $html);
    }

    public function testIncludesSvgIcon(): void
    {
        $html = copy_button('foo', 'Copy foo');
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('width="13"', $html);
        $this->assertStringContainsString('height="13"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function testEscapesQuotesInValue(): void
    {
        $html = copy_button('a"b', 'Copy a"b');
        $this->assertStringContainsString('a&quot;b', $html);
        $this->assertStringNotContainsString('a"b"', $html);
    }
}
