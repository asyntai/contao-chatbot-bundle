<?php

declare(strict_types=1);

namespace Asyntai\ContaoChatbotBundle\Tests;

use Asyntai\ContaoChatbotBundle\Feed\PageReader;
use PHPUnit\Framework\TestCase;

class PageReaderTest extends TestCase
{
    private const URL = 'https://example.com/about-us';

    public function testItReadsTheTitleAndTheMainText(): void
    {
        $html = '<html><head><title>About us - Spa</title></head><body>'
            .'<header><nav><a href="/">Home</a><a href="/pricing">Pricing</a></nav></header>'
            .'<main id="main"><h1>About us</h1><p>We are open every day from 9:00 to 21:00 and parking is free.</p></main>'
            .'<footer>Copyright Spa</footer></body></html>';

        $item = PageReader::extract($html, self::URL);

        $this->assertNotNull($item);
        $this->assertSame('About us', $item['title']);
        $this->assertSame(self::URL, $item['url']);
        $this->assertStringContainsString('open every day from 9:00', $item['content']);
        $this->assertStringNotContainsString('Pricing', $item['content']);
        $this->assertStringNotContainsString('Copyright', $item['content']);
    }

    public function testItSkipsANoindexPage(): void
    {
        $html = '<html><head><meta name="robots" content="noindex,nofollow"></head>'
            .'<body><main><h1>Notes</h1><p>Internal notes about the boiler repair plan for next winter.</p></main></body></html>';

        $this->assertNull(PageReader::extract($html, self::URL));
    }

    public function testItLeavesOutPartsMarkedForNoIndex(): void
    {
        $html = '<html><body><main><h1>News</h1>'
            .'<p>Our spa news page lists the latest changes for all of our guests.</p>'
            .'<!-- indexer::stop --><p>LIST-TEASER duplicated from the full post</p><!-- indexer::continue -->'
            .'</main></body></html>';

        $item = PageReader::extract($html, self::URL);

        $this->assertNotNull($item);
        $this->assertStringNotContainsString('LIST-TEASER', $item['content']);
    }

    public function testItSkipsAPageWithoutWords(): void
    {
        $html = '<html><head><title>Empty</title></head><body><main><h1>Empty</h1></main></body></html>';

        $this->assertNull(PageReader::extract($html, self::URL));
    }

    public function testItUsesTheBodyWhenMainIsThin(): void
    {
        $html = '<html><head><title>Contact</title></head><body><main></main>'
            .'<div class="content"><p>Call us on 123 456 789 or write to us. We answer within one working day.</p></div>'
            .'</body></html>';

        $item = PageReader::extract($html, self::URL);

        $this->assertNotNull($item);
        $this->assertSame('Contact', $item['title']);
        $this->assertStringContainsString('123 456 789', $item['content']);
    }

    public function testScriptsAndStylesAreNotContent(): void
    {
        $html = '<html><body><main><h1>Prices</h1><script>var secret = "TRACKING-CODE";</script>'
            .'<style>.x{color:red}</style><p>A day ticket costs 690 CZK and a massage costs 1,290 CZK.</p></main></body></html>';

        $item = PageReader::extract($html, self::URL);

        $this->assertNotNull($item);
        $this->assertStringNotContainsString('TRACKING-CODE', $item['content']);
        $this->assertStringNotContainsString('color:red', $item['content']);
    }
}
