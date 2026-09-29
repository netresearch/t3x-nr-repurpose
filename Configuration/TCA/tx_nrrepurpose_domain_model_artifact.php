<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

return [
    'ctrl' => [
        'title'            => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_artifact',
        'label'            => 'type',
        'tstamp'           => 'tstamp',
        'crdate'           => 'crdate',
        'hideTable'        => true,
        'typeicon_classes' => ['default' => 'tx-nrrepurpose-artifact'],
    ],
    'columns' => [
        'job'               => ['config' => ['type' => 'passthrough']],
        'type'              => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_artifact.type', 'config' => ['type' => 'input', 'readOnly' => true]],
        'variant'           => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_artifact.variant', 'config' => ['type' => 'input', 'readOnly' => true]],
        'file_uid'          => ['config' => ['type' => 'passthrough']],
        'subtitle_file_uid' => ['config' => ['type' => 'passthrough']],
        'source_html'       => ['config' => ['type' => 'passthrough']],
        'script_text'       => ['config' => ['type' => 'passthrough']],
        'status'            => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_artifact.status', 'config' => ['type' => 'input', 'readOnly' => true]],
        'error_message'     => ['config' => ['type' => 'text']],
        'metadata'          => ['config' => ['type' => 'passthrough']],
        // Written by the result view's review and scheduling actions and by the
        // nr_repurpose:publish-due command, never by the record form.
        'review_status'  => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_artifact.review_status', 'config' => ['type' => 'input', 'readOnly' => true]],
        'reviewed_by'    => ['config' => ['type' => 'passthrough']],
        'reviewed_at'    => ['config' => ['type' => 'passthrough']],
        'publish_at'     => ['config' => ['type' => 'passthrough']],
        'publish_status' => ['label' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang_db.xlf:tx_nrrepurpose_domain_model_artifact.publish_status', 'config' => ['type' => 'input', 'readOnly' => true]],
        'published_at'   => ['config' => ['type' => 'passthrough']],
        'publish_error'  => ['config' => ['type' => 'passthrough']],
    ],
    'types' => [
        '0' => ['showitem' => 'type, variant, status, error_message, review_status, publish_status'],
    ],
];
