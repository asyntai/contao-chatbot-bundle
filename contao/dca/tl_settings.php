<?php

declare(strict_types=1);

use Asyntai\ContaoChatbotBundle\Widget\WidgetSnippet;
use Contao\CoreBundle\DataContainer\PaletteManipulator;

PaletteManipulator::create()
    ->addLegend('asyntai_legend', 'global_legend', PaletteManipulator::POSITION_AFTER)
    ->addField(['asyntaiWidgetId', 'asyntaiScriptUrl'], 'asyntai_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('default', 'tl_settings')
;

$GLOBALS['TL_DCA']['tl_settings']['fields']['asyntaiWidgetId'] = [
    'inputType' => 'text',
    'eval' => ['tl_class' => 'w50', 'maxlength' => 512, 'decodeEntities' => true],
    'save_callback' => [
        // Store a clean ID, even when the whole dashboard snippet is pasted in.
        static fn ($value) => WidgetSnippet::extractId(is_string($value) ? $value : ''),
    ],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['asyntaiScriptUrl'] = [
    'inputType' => 'text',
    'eval' => ['tl_class' => 'w50', 'maxlength' => 255, 'decodeEntities' => true],
    'save_callback' => [
        static function ($value) {
            $value = is_string($value) ? trim($value) : '';

            // An empty field means "use the standard address".
            return '' === $value ? '' : WidgetSnippet::normalizeScriptUrl($value);
        },
    ],
];
