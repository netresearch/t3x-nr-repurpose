<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Repurpose Artifact',
        'label' => 'type',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'hideTable' => true,
    ],
    'columns' => [
        'job' => ['config' => ['type' => 'passthrough']],
        'type' => ['label' => 'Type', 'config' => ['type' => 'input', 'readOnly' => true]],
        'variant' => ['label' => 'Variant', 'config' => ['type' => 'input', 'readOnly' => true]],
        'file_uid' => ['config' => ['type' => 'passthrough']],
        'subtitle_file_uid' => ['config' => ['type' => 'passthrough']],
        'source_html' => ['config' => ['type' => 'passthrough']],
        'script_text' => ['config' => ['type' => 'passthrough']],
        'status' => ['label' => 'Status', 'config' => ['type' => 'input', 'readOnly' => true]],
        'error_message' => ['config' => ['type' => 'text']],
        'metadata' => ['config' => ['type' => 'passthrough']],
    ],
    'types' => [
        '0' => ['showitem' => 'type, variant, status, error_message'],
    ],
];
