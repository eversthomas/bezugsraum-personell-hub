<?php

declare(strict_types=1);

namespace Tests\Items;

use App\Items\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    public function testRendersBasicMarkdown(): void
    {
        $html = (new MarkdownRenderer())->render("# Titel\n\n**fett** und *schräg*\n\n- eins\n- zwei");

        self::assertStringContainsString('<h1>Titel</h1>', $html);
        self::assertStringContainsString('<strong>fett</strong>', $html);
        self::assertStringContainsString('<li>zwei</li>', $html);
    }

    public function testRawHtmlIsStripped(): void
    {
        $html = (new MarkdownRenderer())->render("<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n<b>hi</b>");

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('onerror', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    public function testUnsafeLinksAreRemoved(): void
    {
        $html = (new MarkdownRenderer())->render("[a](javascript:alert(1)) [b](data:text/html;base64,AAAA) [c](vbscript:x)");

        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('data:text', $html);
        self::assertStringNotContainsString('vbscript:', $html);
    }

    public function testExternalLinksOpenSafely(): void
    {
        $html = (new MarkdownRenderer())->render('[Seite](https://example.test/x)');

        self::assertStringContainsString('href="https://example.test/x"', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('noopener', $html);
        self::assertStringContainsString('noreferrer', $html);
    }
}
