<?php

declare(strict_types=1);

defined('TYPO3') or die();

use Netresearch\NrLlm\Domain\Enum\ModelCapability;

// Backend capability permission options for nr_repurpose runs. nr-llm has no dedicated
// IMAGE/SPEECH capability, so audio generation gates on AUDIO and image/vision on VISION.
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['nrrepurpose'] = [
    'header' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang.xlf:perm.header',
    'items' => [
        'generate_audio' => [
            'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang.xlf:perm.generate_audio',
            'actions-volume-up',
            'Generate podcast audio (maps to nr_llm capability ' . ModelCapability::AUDIO->value . ')',
        ],
        'generate_vision' => [
            'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang.xlf:perm.generate_vision',
            'actions-image',
            'Generate AI imagery (maps to nr_llm capability ' . ModelCapability::VISION->value . ')',
        ],
    ],
];
