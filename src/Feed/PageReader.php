<?php

declare(strict_types=1);

namespace Asyntai\ContaoChatbotBundle\Feed;

/**
 * Reads pages the way a logged-out visitor gets them.
 *
 * Every address is fetched with a normal request to this website, and only a
 * 200 answer that stays on the same address is used. A draft, a members-only
 * page or anything else the site refuses to show to an anonymous visitor is
 * therefore never sent.
 */
final class PageReader
{
    /** Header on our own requests, so the chat widget is left out. */
    public const RENDER_HEADER = 'X-Asyntai-Feed';

    /**
     * @param list<string> $urls
     *
     * @return list<array{type: string, title: string, url: string, content: string}>
     */
    public function read(array $urls): array
    {
        if (!$urls || !\function_exists('curl_multi_init')) {
            return [];
        }

        $multi = curl_multi_init();
        $handles = [];

        foreach ($urls as $i => $url) {
            $curl = curl_init($url);
            curl_setopt_array($curl, $this->options());
            curl_multi_add_handle($multi, $curl);
            $handles[$i] = $curl;
        }

        do {
            $status = curl_multi_exec($multi, $running);

            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && CURLM_OK === $status);

        $items = [];

        foreach ($handles as $i => $curl) {
            $answer = $this->answer($curl, (string) curl_multi_getcontent($curl));
            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);

            // A page that failed for a moment would otherwise drop out of the
            // chatbot's knowledge until the next read: one more try on its own.
            if (0 === $answer[0] || $answer[0] >= 500) {
                $answer = $this->readOne($urls[$i]);
            }

            $item = $this->accept($answer, $urls[$i]);

            if (null !== $item) {
                $items[] = $item;
            }
        }

        curl_multi_close($multi);

        return $items;
    }

    /**
     * Title and main text of one page, or null when it has none.
     *
     * @return array{type: string, title: string, url: string, content: string}|null
     */
    public static function extract(string $html, string $url): array|null
    {
        if (preg_match('/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex/i', $html)) {
            return null;
        }

        // Parts a Contao layout marks as "not for the search index".
        $html = (string) preg_replace('#<!--\s*indexer::stop\s*-->.*?<!--\s*indexer::continue\s*-->#is', ' ', $html);

        $title = '';

        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $title = self::text($m[1]);
        }

        if ('' === $title && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = self::text($m[1]);
        }

        // The main or article element when it really holds the page's words.
        // Some layouts keep the text beside an almost empty main element, so
        // the whole body is used whenever those are thin.
        $text = '';

        foreach (['main', 'article', 'body'] as $tag) {
            if (!preg_match('/<'.$tag.'\b[^>]*>(.*)<\/'.$tag.'>/is', $html, $m)) {
                continue;
            }

            $candidate = self::text(self::stripChrome($m[1]));

            if (mb_strlen($candidate) > mb_strlen($text)) {
                $text = $candidate;
            }

            if (mb_strlen($candidate) >= 200) {
                break;
            }
        }

        if ('' === $text) {
            $text = self::text(self::stripChrome($html));
        }

        if ('' === $title || mb_strlen($text) < 40) {
            return null;
        }

        return [
            'type' => 'page',
            'title' => mb_substr($title, 0, 300),
            'url' => $url,
            'content' => $text,
        ];
    }

    /** @return array{0: int, 1: string, 2: string, 3: string} */
    private function readOne(string $url): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, $this->options());
        $html = curl_exec($curl);
        $answer = $this->answer($curl, \is_string($html) ? $html : '');
        curl_close($curl);

        return $answer;
    }

    /** @return array{0: int, 1: string, 2: string, 3: string} */
    private function answer(\CurlHandle $curl, string $html): array
    {
        return [
            (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            strtolower((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE)),
            (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
            $html,
        ];
    }

    private function options(): array
    {
        return [
            CURLOPT_RETURNTRANSFER => true,
            // Followed, then checked in accept(): only a redirect that lands on
            // the same address is used. Anything else is a login wall or a
            // moved page.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Accept: text/html', self::RENDER_HEADER.': 1'],
            CURLOPT_USERAGENT => 'Asyntai AI Chatbot for Contao (page share)',
        ];
    }

    /**
     * @param array{0: int, 1: string, 2: string, 3: string} $answer
     *
     * @return array{type: string, title: string, url: string, content: string}|null
     */
    private function accept(array $answer, string $url): array|null
    {
        [$code, $type, $landed, $html] = $answer;

        if (200 !== $code || '' === $html || !str_contains($type, 'html')) {
            return null;
        }

        if (rtrim($landed, '/') !== rtrim($url, '/')) {
            return null;
        }

        return self::extract($html, $url);
    }

    /** Navigation, page chrome and code are not content. */
    private static function stripChrome(string $html): string
    {
        return (string) preg_replace(
            '#<(script|style|noscript|svg|template|nav|header|footer|aside|form|iframe)\b[^>]*>.*?</\1>#is',
            ' ',
            $html,
        );
    }

    private static function text(string $html): string
    {
        $html = (string) preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|tr|section)>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/\s*\n\s*/', "\n", $text);

        return trim($text);
    }
}
