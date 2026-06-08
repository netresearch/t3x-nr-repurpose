<?php

declare(strict_types=1);

use Netresearch\NrRepurpose\Controller\JobController;

return [
    'web_nrrepurpose' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'user',
        'iconIdentifier' => 'tx-nrrepurpose-module',
        'path' => '/module/web/nr-repurpose',
        'labels' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'NrRepurpose',
        'controllerActions' => [
            JobController::class => ['list', 'new', 'create', 'show'],
        ],
    ],
];
