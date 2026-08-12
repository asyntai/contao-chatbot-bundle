<?php

declare(strict_types=1);

namespace Asyntai\ContaoChatbotBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class AsyntaiChatbotBundle extends Bundle
{
    /**
     * Contao looks for the contao/ resource folder in the bundle root, not in src/.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
