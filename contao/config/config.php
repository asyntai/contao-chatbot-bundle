<?php

declare(strict_types=1);

use Asyntai\ContaoChatbotBundle\Backend\ConnectModule;

$GLOBALS['BE_MOD']['system']['asyntai_chatbot'] = [
    'callback' => ConnectModule::class,
];
