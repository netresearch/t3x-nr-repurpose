<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'tx-nrrepurpose-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_repurpose/Resources/Public/Icons/module.svg',
    ],
    // Record icon for tx_nrrepurpose_domain_model_artifact.
    'tx-nrrepurpose-artifact' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_repurpose/Resources/Public/Icons/artifact.svg',
    ],
    // Extension icon (branded tile) shown in the Extension Manager / TER.
    'nr_repurpose' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_repurpose/Resources/Public/Icons/Extension.svg',
    ],
];
