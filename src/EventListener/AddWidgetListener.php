<?php

declare(strict_types=1);

namespace Asyntai\ContaoChatbotBundle\EventListener;

use Asyntai\ContaoChatbotBundle\Feed\PageReader;
use Asyntai\ContaoChatbotBundle\Widget\WidgetSnippet;
use Contao\Config;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\LayoutModel;
use Contao\PageModel;
use Contao\PageRegular;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Adds the chat widget to the end of the body on every front end page.
 */
#[AsHook('generatePage')]
class AddWidgetListener
{
    public function __construct(private readonly RequestStack|null $requestStack = null)
    {
    }

    public function __invoke(PageModel $pageModel, LayoutModel $layout, PageRegular $pageRegular): void
    {
        $id = WidgetSnippet::extractId(Config::get('asyntaiWidgetId'));

        if ('' === $id) {
            return;
        }

        // Our own page-share reads do not need the widget.
        if ('1' === $this->requestStack?->getMainRequest()?->headers->get(PageReader::RENDER_HEADER)) {
            return;
        }

        $scriptUrl = WidgetSnippet::normalizeScriptUrl(Config::get('asyntaiScriptUrl'));

        // A fixed key, so the script is never added twice.
        $GLOBALS['TL_BODY']['asyntai_chat_widget'] = WidgetSnippet::build($id, $scriptUrl);
    }
}
