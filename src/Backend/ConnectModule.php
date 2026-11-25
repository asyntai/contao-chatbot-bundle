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
        $this->Template->lang = $GLOBALS['TL_LANG']['ASYNTAI'] ?? [];
    }
}
