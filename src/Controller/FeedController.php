<?php

declare(strict_types=1);

namespace Asyntai\ContaoChatbotBundle\Controller;

use Asyntai\ContaoChatbotBundle\Feed\PageReader;
use Asyntai\ContaoChatbotBundle\State;
use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The signed, read-only feed Asyntai reads this website's pages from.
 *
 * Format: Asyntai store feed v1. The product feed is always empty (a Contao
 * website shares pages) and names the pages feed in `store.pages_url`.
 *
 * WHICH PAGES: the addresses of the website's own XML sitemap, built by Contao
 * for an anonymous visitor. News, events and FAQ add their entries there, and
 * Contao already leaves out drafts, members-only pages and noindex pages.
 */
final class FeedController
{
    /** How far a signed request's clock may drift, in seconds. */
    private const CLOCK_SKEW = 300;

    /** Pages read per feed page. Each one is a request to this website. */
    private const PAGE_SIZE = 8;

    /** A website with more addresses than this is answering wrongly. */
    private const MAX_URLS = 3000;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly HttpKernelInterface $httpKernel,
        private readonly PageReader $reader,
    ) {
    }

    #[Route('/_asyntai/feed', name: 'asyntai_chatbot_feed', methods: ['GET'], defaults: ['_token_check' => false])]
    public function products(Request $request): JsonResponse
    {
        $this->framework->initialize();

        if ($refused = $this->refuse($request)) {
            return $refused;
        }

        $site = State::siteUrl() ?: $request->getSchemeAndHttpHost().$request->getBasePath();

        return $this->json([
            'ok' => true,
            'store' => [
                'name' => (string) ($request->getHost() ?: parse_url($site, PHP_URL_HOST)),
                'url' => $site,
                'currency' => '',
                'version' => ContaoCoreBundle::getVersion(),
                'pages_url' => $site.State::PAGES_PATH,
            ],
            'page' => 1,
            'pages' => 0,
            'total' => 0,
            'categories' => [],
            'products' => [],
        ]);
    }

    #[Route('/_asyntai/feed/pages', name: 'asyntai_chatbot_feed_pages', methods: ['GET'], defaults: ['_token_check' => false])]
    public function pages(Request $request): JsonResponse
    {
        $this->framework->initialize();

        if ($refused = $this->refuse($request)) {
            return $refused;
        }

        $page = max(1, (int) $request->query->get('page', '1'));
        // The page size is ours, whatever was asked for: every item is a request
        // to this website, and a large page would run into the reader's timeout.
        $urls = $this->urls($request);
        $slice = \array_slice($urls, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        return $this->json([
            'ok' => true,
            'page' => $page,
            'pages' => (int) ceil(\count($urls) / self::PAGE_SIZE),
            'total' => \count($urls),
            'items' => $this->reader->read($slice),
        ]);
    }

    /**
     * A 403 answer when the request is not a valid signed call, else null.
     * Signed over the raw query values, in a fixed order, before clamping.
     */
    private function refuse(Request $request): JsonResponse|null
    {
        $tokens = State::acceptedTokens();

        if (!$tokens) {
            return $this->json(['ok' => false, 'error' => 'Sharing is switched off on this website.'], 403);
        }

        $query = $request->query;
        $ts = (string) $query->get('ts', '');

        if ('' === $ts || !ctype_digit($ts)) {
            return $this->json(['ok' => false, 'error' => 'Missing timestamp.'], 403);
        }

        if (abs(time() - (int) $ts) > self::CLOCK_SKEW) {
            return $this->json(['ok' => false, 'error' => 'The request has expired.'], 403);
        }

        $signed = 'page='.(string) $query->get('page', '')
            .'&limit='.(string) $query->get('limit', '')
            .'&ids='.(string) $query->get('ids', '')
            .'&ts='.$ts;
        $sig = strtolower((string) $query->get('sig', ''));

        foreach ($tokens as $token) {
            if ('' !== $sig && hash_equals(hash_hmac('sha256', $signed, $token), $sig)) {
                return null;
            }
        }

        return $this->json(['ok' => false, 'error' => 'Bad signature.'], 403);
    }

    /**
     * The sitemap addresses, sorted so the paging is stable between calls.
     *
     * @return list<string>
     */
    private function urls(Request $request): array
    {
        // A website in a sub folder: the script name comes along, so the sub
        // request keeps the base path and the sitemap route still matches.
        $basePath = $request->getBasePath();
        $server = [];

        if ('' !== $basePath) {
            $server = [
                'SCRIPT_NAME' => $basePath.'/index.php',
                'SCRIPT_FILENAME' => (string) $request->server->get('SCRIPT_FILENAME'),
            ];
        }

        $sub = Request::create($request->getSchemeAndHttpHost().$basePath.'/sitemap.xml', 'GET', [], [], [], $server);
        $sub->headers->set(PageReader::RENDER_HEADER, '1');

        try {
            $response = $this->httpKernel->handle($sub, HttpKernelInterface::SUB_REQUEST, false);
        } catch (\Throwable) {
            return [];
        }

        if (200 !== $response->getStatusCode()) {
            return [];
        }

        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', (string) $response->getContent(), $matches);

        $host = strtolower($request->getHost());
        $urls = [];

        foreach ($matches[1] as $raw) {
            $url = html_entity_decode($raw, ENT_QUOTES | ENT_XML1, 'UTF-8');

            // Only this website's own pages.
            if (!preg_match('#^https?://#i', $url) || strtolower((string) parse_url($url, PHP_URL_HOST)) !== $host) {
                continue;
            }

            $urls[$url] = true;
        }

        $urls = array_keys($urls);
        sort($urls);

        return \array_slice($urls, 0, self::MAX_URLS);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
