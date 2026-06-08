<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Repurpose Job',
        'label' => 'source_value',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'default_sortby' => 'crdate DESC',
        'iconfile' => 'EXT:nr_repurpose/Resources/Public/Icons/module.svg',
    ],
    'columns' => [
        'source_type' => [
            'label' => 'Source type',
            'config' => [
                'type' => 'select', 'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Webpage URL', 'value' => 'url'],
                    ['label' => 'PDF URL', 'value' => 'pdf_url'],
                    ['label' => 'PDF file (FAL)', 'value' => 'pdf_fal'],
                ],
                'default' => 'url',
            ],
        ],
        'source_value' => [
            'label' => 'Source URL',
            'config' => ['type' => 'input', 'size' => 60, 'eval' => 'trim'],
        ],
        'theme' => [
            'label' => 'Theme',
            'config' => [
                'type' => 'select', 'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Netresearch CI', 'value' => 'nr'],
                    ['label' => 'Neutral', 'value' => 'neutral'],
                ],
                'default' => 'nr',
            ],
        ],
        'pdf_mode' => [
            'label' => 'PDF extraction mode',
            'displayCond' => 'FIELD:source_type:IN:pdf_url,pdf_fal',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Auto (staggered)', 'value' => 'auto'],
                    ['label' => 'Embedded text only', 'value' => 'text'],
                    ['label' => 'Vision OCR', 'value' => 'vision'],
                    ['label' => 'Layout / tables', 'value' => 'tables'],
                ],
                'default' => 'auto',
            ],
        ],
        'source_pdf' => [
            'label' => 'Source PDF (FAL)',
            'displayCond' => 'FIELD:source_type:=:pdf_fal',
            'config' => [
                'type' => 'file',
                'allowed' => 'pdf',
                'maxitems' => 1,
                'appearance' => [
                    'fileByUrlAllowed' => false,
                ],
            ],
        ],
        'want_podcast' => ['label' => 'Podcast', 'config' => ['type' => 'check', 'default' => 1]],
        'want_schaubild' => ['label' => 'Schaubild', 'config' => ['type' => 'check', 'default' => 1]],
        'want_story' => ['label' => 'Story', 'config' => ['type' => 'check', 'default' => 1]],
        'status' => ['label' => 'Status', 'config' => ['type' => 'input', 'readOnly' => true]],
        'progress' => ['label' => 'Progress', 'config' => ['type' => 'number', 'readOnly' => true]],
        'current_step' => ['label' => 'Step', 'config' => ['type' => 'input', 'readOnly' => true]],
        'error_message' => ['label' => 'Error', 'config' => ['type' => 'text', 'readOnly' => true]],
        'language_detected' => ['label' => 'Language', 'config' => ['type' => 'input', 'readOnly' => true]],
        'be_user' => ['config' => ['type' => 'passthrough']],
        'artifacts' => [
            'label' => 'Artifacts',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_nrrepurpose_domain_model_artifact',
                'foreign_field' => 'job',
            ],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'source_type, source_value, source_pdf, pdf_mode, theme, want_podcast, want_schaubild, want_story, status, progress, current_step, error_message, language_detected, artifacts'],
    ],
];
