<?php

declare(strict_types=1);

namespace Asyntai\ContaoChatbotBundle\Widget;

/**
 * Builds the loader script for the Asyntai chat widget.
 */
final class WidgetSnippet
{
    public const DEFAULT_SCRIPT_URL = 'https://widget.asyntai.com/static/js/chat-widget.js';

    /**
     * People paste the whole snippet from the Asyntai dashboard, so accept that
     * as well as a bare widget ID. Returns an empty string when there is no ID.
     */
    public static function extractId(string|null $raw): string
    {
        $value = trim((string) $raw);

        if ('' === $value) {
            return '';
        }

        if (preg_match('/data-asyntai-id\s*=\s*["\']([^"\']+)["\']/', $value, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/asyntai_[A-Za-z0-9]+/', $value, $matches)) {
            return $matches[0];
        }

        // An ID on its own, without the usual prefix.
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return $value;
        }

        return '';
    }

    public static function normalizeScriptUrl(string|null $raw): string
    {
        $value = trim((string) $raw);

        if ('' === $value || !preg_match('#^https?://#i', $value)) {
            return self::DEFAULT_SCRIPT_URL;
        }

        return $value;
    }

    /**
     * The widget is fetched only after the load event. An async script tag would
     * still hold the load event open until its request finishes, so a slow
     * Asyntai server would slow down the page it sits on.
     */
    public static function build(string $id, string $scriptUrl): string
    {
        if ('' === $id) {
            return '';
        }

        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

        return '<script>(function(){var l=function(){var s=document.createElement("script");'
            .'s.src='.json_encode($scriptUrl, $flags).';s.async=true;'
            .'s.setAttribute("data-asyntai-id",'.json_encode($id, $flags).');'
            .'document.head.appendChild(s)};'
            .'if(document.readyState==="complete"){l()}'
            .'else{window.addEventListener("load",l)}})();</script>';
    }
}
