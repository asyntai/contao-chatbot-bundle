<?php

declare(strict_types=1);

namespace Asyntai\ContaoChatbotBundle;

use Asyntai\ContaoChatbotBundle\Widget\WidgetSnippet;
use Contao\Config;
use Contao\PageModel;

/**
 * Settings, connection state and the calls this bundle makes to Asyntai.
 *
 * Everything is kept in the local configuration, where version 1.0.0 already
 * keeps the widget ID, so an existing setup keeps working after the update.
 * The feed token and the connect handshake are never fields of a back end
 * form, so they are never printed into a page.
 */
final class State
{
    public const KEY_WIDGET_ID = 'asyntaiWidgetId';
    public const KEY_SCRIPT_URL = 'asyntaiScriptUrl';
    public const KEY_ACCOUNT_EMAIL = 'asyntaiAccountEmail';
    public const KEY_SITE_URL = 'asyntaiSiteUrl';
    public const KEY_FEED_TOKEN = 'asyntaiFeedToken';
    public const KEY_FEED_ENABLED = 'asyntaiFeedEnabled';
    public const KEY_PENDING_TOKEN = 'asyntaiPendingToken';
    public const KEY_STATE = 'asyntaiConnectState';
    public const KEY_STATE_AT = 'asyntaiConnectStateAt';

    public const FEED_PATH = '/_asyntai/feed';
    public const PAGES_PATH = '/_asyntai/feed/pages';

    /** Where this site's PHP talks to Asyntai. Overridable for a test server. */
    public static function origin(): string
    {
        $origin = $_SERVER['ASYNTAI_CHATBOT_ORIGIN'] ?? $_ENV['ASYNTAI_CHATBOT_ORIGIN'] ?? getenv('ASYNTAI_CHATBOT_ORIGIN') ?: '';

        return '' !== $origin ? rtrim((string) $origin, '/') : 'https://asyntai.com';
    }

    // -----------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------

    public static function get(string $key): string
    {
        $value = Config::get($key);

        return \is_scalar($value) ? (string) $value : '';
    }

    /** Whether this request changed the local configuration. */
    private static bool $changed = false;

    public static function set(string $key, string $value): void
    {
        Config::set($key, $value);
        Config::persist($key, $value);
        self::$changed = true;
    }

    public static function forget(string $key): void
    {
        Config::set($key, '');
        Config::remove($key);
        self::$changed = true;
    }

    /**
     * Write pending changes to the local configuration file now. Only after a
     * change: Contao's save() writes what it has loaded, and before a change it
     * has loaded nothing.
     */
    public static function save(): void
    {
        if (!self::$changed) {
            return;
        }

        Config::getInstance()->save();
        self::$changed = false;
    }

    // -----------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------

    public static function widgetId(): string
    {
        return WidgetSnippet::extractId(self::get(self::KEY_WIDGET_ID));
    }

    /** Whether Asyntai may read the shared pages right now. */
    public static function sharingOn(): bool
    {
        return '1' === self::get(self::KEY_FEED_ENABLED) && '' !== self::get(self::KEY_FEED_TOKEN);
    }

    /**
     * Tokens the feed accepts: the live one while sharing is on, and the one
     * parked by the last connect. Asyntai starts reading the moment the owner
     * signs in, which is a moment before this site stores the sign-in result.
     *
     * @return list<string>
     */
    public static function acceptedTokens(): array
    {
        $tokens = [];

        if (self::sharingOn()) {
            $tokens[] = self::get(self::KEY_FEED_TOKEN);
        }

        $pending = self::get(self::KEY_PENDING_TOKEN);

        if ('' !== $pending) {
            $tokens[] = $pending;
        }

        return $tokens;
    }

    /**
     * The public address of the website. The first published root page with a
     * domain wins; without one, the address the back end is opened on. Both
     * keep the folder Contao is installed in, for example /shop.
     */
    public static function detectSiteUrl(string $fallback, string $basePath = ''): string
    {
        $basePath = rtrim($basePath, '/');

        $roots = PageModel::findPublishedRootPages();

        if (null !== $roots) {
            $first = '';

            foreach ($roots as $root) {
                if ('' === (string) $root->dns) {
                    continue;
                }

                $url = ($root->useSSL ? 'https://' : 'http://').$root->dns.$basePath;

                if ($root->fallback) {
                    return $url;
                }

                $first = '' === $first ? $url : $first;
            }

            if ('' !== $first) {
                return $first;
            }
        }

        return rtrim($fallback, '/').$basePath;
    }

    public static function siteUrl(): string
    {
        return rtrim(self::get(self::KEY_SITE_URL), '/');
    }

    // -----------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------

    /** Body of a GET on a 2xx answer, or null. */
    public static function httpGet(string $url, int $timeout = 10): string|null
    {
        return self::request('GET', $url, null, $timeout, ['Accept: application/json']);
    }

    /** Body of a JSON POST on a 2xx answer, or null. */
    public static function httpPostJson(string $url, array $payload, int $timeout = 15): string|null
    {
        return self::request('POST', $url, (string) json_encode($payload), $timeout, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
    }

    /** The "error" text of the last answer that was not 2xx, if it had one. */
    public static string $lastError = '';

    private static function request(string $method, string $url, string|null $body, int $timeout, array $headers): string|null
    {
        self::$lastError = '';

        if (!\function_exists('curl_init')) {
            return null;
        }

        $curl = curl_init($url);

        if (false === $curl) {
            return null;
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'Asyntai AI Chatbot for Contao',
        ]);

        if ('POST' === $method) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, (string) $body);
        }

        $response = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!\is_string($response) || $code < 200 || $code > 299) {
            $decoded = \is_string($response) ? json_decode($response, true) : null;

            if (\is_array($decoded) && \is_string($decoded['error'] ?? null)) {
                self::$lastError = mb_substr($decoded['error'], 0, 200);
            }

            return null;
        }

        return $response;
    }
}
