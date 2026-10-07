<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$EM_CONF[$_EXTKEY] = [
    'title' => 'Content Repurpose',
    'description' => 'Turn a webpage or PDF into a podcast, a diagram, an Instagram story, documents and ready-to-use texts.',
    'category' => 'module',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => 'typo3@netresearch.de',
    'author_company' => 'Netresearch DTT GmbH',
    'state' => 'alpha',
    'version' => '0.11.0',
    'constraints' => [
        'depends' => [
            'php' => '8.3.0-8.99.99',
            'typo3' => '14.3.0-14.99.99',
            'nr_llm' => '0.35.0-0.39.99',
            'nr_vault' => '1.1.0-1.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
