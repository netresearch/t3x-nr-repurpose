<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

return [
    'ctrl' => [
        'title'            => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job',
        'label'            => 'source_value',
        'tstamp'           => 'tstamp',
        'crdate'           => 'crdate',
        'default_sortby'   => 'crdate DESC',
        'typeicon_classes' => ['default' => 'tx-nrrepurpose-module'],
    ],
    'columns' => [
        'source_type' => [
            'label'  => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.source_type',
            'config' => [
                'type'  => 'select', 'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.source_type.url', 'value' => 'url'],
                    ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.source_type.pdf_url', 'value' => 'pdf_url'],
                    ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.source_type.pdf_fal', 'value' => 'pdf_fal'],
                ],
                'default' => 'url',
            ],
        ],
        'source_value' => [
            'label'  => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.source_value',
            'config' => ['type' => 'input', 'size' => 60, 'eval' => 'trim'],
        ],
        'theme' => [
            'label'  => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.theme',
            'config' => [
                'type'  => 'select', 'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.theme.nr', 'value' => 'nr'],
                    ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.theme.neutral', 'value' => 'neutral'],
                ],
                'default' => 'nr',
            ],
        ],
        'pdf_mode' => [
            'label'       => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.pdf_mode',
            'displayCond' => 'FIELD:source_type:IN:pdf_url,pdf_fal',
            'config'      => [
                'type'       => 'select',
                'renderType' => 'selectSingle',
                'items'      => [
                    ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.pdf_mode.auto', 'value' => 'auto'],
                    ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.pdf_mode.text', 'value' => 'text'],
                    ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.pdf_mode.vision', 'value' => 'vision'],
                    ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.pdf_mode.tables', 'value' => 'tables'],
                ],
                'default' => 'auto',
            ],
        ],
        'source_pdf' => [
            'label'       => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.source_pdf',
            'displayCond' => 'FIELD:source_type:=:pdf_fal',
            'config'      => [
                'type'       => 'file',
                'allowed'    => 'pdf',
                'maxitems'   => 1,
                'appearance' => [
                    'fileByUrlAllowed' => false,
                ],
            ],
        ],
        'want_podcast'      => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.want_podcast', 'config' => ['type' => 'check', 'default' => 1]],
        'want_schaubild'    => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.want_schaubild', 'config' => ['type' => 'check', 'default' => 1]],
        'want_story'        => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.want_story', 'config' => ['type' => 'check', 'default' => 1]],
        'want_exec_summary' => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.want_exec_summary', 'config' => ['type' => 'check', 'default' => 0]],
        'want_faq'          => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.want_faq', 'config' => ['type' => 'check', 'default' => 0]],
        'want_social_post'  => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.want_social_post', 'config' => ['type' => 'check', 'default' => 0]],
        'want_newsletter'   => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.want_newsletter', 'config' => ['type' => 'check', 'default' => 0]],
        'want_slide_deck'   => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.want_slide_deck', 'config' => ['type' => 'check', 'default' => 0]],
        'want_handout'      => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.want_handout', 'config' => ['type' => 'check', 'default' => 0]],
        'want_video'        => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.want_video', 'config' => ['type' => 'check', 'default' => 0]],
        // JSON snapshot of the New-form prompt-snippet selection (PromptSnippetSelection);
        // written by the module form only, hence passthrough (not editable in the record view).
        'prompt_snippets'   => ['config' => ['type' => 'passthrough']],
        'status'            => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.status', 'config' => ['type' => 'input', 'readOnly' => true]],
        'progress'          => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.progress', 'config' => ['type' => 'number', 'readOnly' => true]],
        'current_step'      => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.current_step', 'config' => ['type' => 'input', 'readOnly' => true]],
        'error_message'     => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.error_message', 'config' => ['type' => 'text', 'readOnly' => true]],
        'language_detected' => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.language_detected', 'config' => ['type' => 'input', 'readOnly' => true]],
        'be_user'           => ['config' => ['type' => 'passthrough']],
        'artifacts'         => [
            'label'  => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_job.artifacts',
            'config' => [
                'type'          => 'inline',
                'foreign_table' => 'tx_nrrepurpose_domain_model_artifact',
                'foreign_field' => 'job',
            ],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'source_type, source_value, source_pdf, pdf_mode, theme, want_podcast, want_schaubild, want_story, want_exec_summary, want_faq, want_social_post, want_newsletter, want_slide_deck, want_handout, want_video, status, progress, current_step, error_message, language_detected, artifacts'],
    ],
];
