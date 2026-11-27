<?php

declare(strict_types=1);

namespace Asyntai\ContaoChatbotBundle\Backend;

use Asyntai\ContaoChatbotBundle\State;
use Contao\BackendModule;
use Contao\BackendUser;
use Contao\System;

/**
 * System → Asyntai AI Chatbot: connect the website and choose what the
 * chatbot may read.
 */
class ConnectModule extends BackendModule
{
    protected $strTemplate = 'be_asyntai_connect';

    protected function compile(): void
    {
        System::loadLanguageFile('default');

        $container = System::getContainer();
        $user = BackendUser::getInstance();

        $this->Template->isAdmin = (bool) $user->isAdmin;
        $this->Template->widgetId = State::widgetId();
        $this->Template->accountEmail = State::get(State::KEY_ACCOUNT_EMAIL);
        $this->Template->sharing = State::sharingOn();
        $this->Template->hasToken = '' !== State::get(State::KEY_FEED_TOKEN);
        $this->Template->requestToken = $container->get('contao.csrf.token_manager')->getDefaultTokenValue();
        // A real action name: the route only accepts known actions. The page
        // script swaps the last path segment for the action it calls.
        $this->Template->actionUrl = $container->get('router')->generate('asyntai_chatbot_connect', ['action' => 'status']);
        $lang = $GLOBALS['TL_LANG']['ASYNTAI'] ?? [];
        $this->Template->lang = $lang;

        // The page script's texts, already safe to print inside a script tag.
        $js = [];

        foreach ($lang as $key => $text) {
            if (str_starts_with((string) $key, 'js')) {
                $js[lcfirst(substr((string) $key, 2))] = (string) $text;
            }
        }

        $this->Template->jsStrings = json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    }
}
