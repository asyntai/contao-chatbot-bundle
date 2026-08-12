<?php

declare(strict_types=1);

namespace Asyntai\ContaoChatbotBundle\Tests;

use Asyntai\ContaoChatbotBundle\Widget\WidgetSnippet;
use PHPUnit\Framework\TestCase;

class WidgetSnippetTest extends TestCase
{
    public function testItReadsTheIdFromAPastedSnippet(): void
    {
        $snippet = '<script src="https://widget.asyntai.com/static/js/chat-widget.js" '
            .'data-asyntai-id="asyntai_2bcd9dfbae24" async></script>';

        $this->assertSame('asyntai_2bcd9dfbae24', WidgetSnippet::extractId($snippet));
    }

    public function testItAcceptsASingleQuotedAttribute(): void
    {
        $this->assertSame(
            'asyntai_abc123',
            WidgetSnippet::extractId("<script data-asyntai-id='asyntai_abc123'></script>"),
        );
    }

    public function testItAcceptsABareId(): void
    {
        $this->assertSame('asyntai_2bcd9dfbae24', WidgetSnippet::extractId(' asyntai_2bcd9dfbae24 '));
    }

    public function testItReturnsAnEmptyStringForEmptyInput(): void
    {
        $this->assertSame('', WidgetSnippet::extractId(''));
        $this->assertSame('', WidgetSnippet::extractId(null));
        $this->assertSame('', WidgetSnippet::extractId('   '));
    }

    public function testItRejectsTextThatHoldsNoId(): void
    {
        $this->assertSame('', WidgetSnippet::extractId('please add my chat bot'));
    }

    public function testItFallsBackToTheStandardScriptUrl(): void
    {
        $this->assertSame(WidgetSnippet::DEFAULT_SCRIPT_URL, WidgetSnippet::normalizeScriptUrl(''));
        $this->assertSame(WidgetSnippet::DEFAULT_SCRIPT_URL, WidgetSnippet::normalizeScriptUrl(null));
        $this->assertSame(WidgetSnippet::DEFAULT_SCRIPT_URL, WidgetSnippet::normalizeScriptUrl('javascript:alert(1)'));
    }

    public function testItKeepsAnOwnScriptUrl(): void
    {
        $this->assertSame(
            'https://cdn.example.com/chat.js',
            WidgetSnippet::normalizeScriptUrl(' https://cdn.example.com/chat.js '),
        );
    }

    public function testItBuildsALoaderThatWaitsForTheLoadEvent(): void
    {
        $html = WidgetSnippet::build('asyntai_2bcd9dfbae24', WidgetSnippet::DEFAULT_SCRIPT_URL);

        $this->assertStringContainsString('window.addEventListener("load",l)', $html);
        $this->assertStringContainsString('data-asyntai-id', $html);
        $this->assertStringContainsString('asyntai_2bcd9dfbae24', $html);
    }

    public function testItBuildsNothingWithoutAnId(): void
    {
        $this->assertSame('', WidgetSnippet::build('', WidgetSnippet::DEFAULT_SCRIPT_URL));
    }

    public function testItCannotBeBrokenOutOfTheScriptTag(): void
    {
        $html = WidgetSnippet::build('asyntai_x', 'https://evil.example.com/a.js?</script><b>');

        $this->assertStringNotContainsString('</script><b>', $html);
        $this->assertSame(1, substr_count($html, '</script>'));
    }
}
