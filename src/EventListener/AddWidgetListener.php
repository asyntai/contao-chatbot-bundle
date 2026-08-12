<?php

declare(strict_types=1);

namespace Asyntai\ContaoChatbotBundle\EventListener;

use Asyntai\ContaoChatbotBundle\Widget\WidgetSnippet;
use Contao\Config;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\LayoutModel;
use Contao\PageModel;
use Contao\PageRegular;

/**
 * Adds the chat widget to the end of the body on every front end page.
 */
#[AsHook('generatePage')]
class AddWidgetListener
{
    public function __invoke(PageModel $pageModel, LayoutModel $layout, PageRegular $pageRegular): void
    {
        $id = WidgetSnippet::extractId(Config::get('asyntaiWidgetId'));

        if ('' === $id) {
            return;
        }

        $scriptUrl = WidgetSnippet::normalizeScriptUrl(Config::get('asyntaiScriptUrl'));

        // A fixed key, so the script is never added twice.
        $GLOBALS['TL_BODY']['asyntai_chat_widget'] = WidgetSnippet::build($id, $scriptUrl);
    }
}
