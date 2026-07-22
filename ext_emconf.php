<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Content Repurpose',
    'description' => 'Turn a webpage or PDF into a podcast, a diagram and an Instagram story (built on nr_llm) - by Netresearch.',
    'category' => 'module',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => 'typo3@netresearch.de',
    'author_company' => 'Netresearch DTT GmbH',
    'state' => 'alpha',
    'version' => '0.2.3',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.99.99',
            'nr_llm' => '0.22.0-0.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
