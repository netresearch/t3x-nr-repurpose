<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

defined('TYPO3') || exit;

// Backend capability permission options for nr_repurpose runs. nr-llm has no dedicated
// IMAGE/SPEECH capability, so audio generation gates on AUDIO and image/vision on VISION;
// the descriptions name those capability values ("audio", "vision") in locallang.xlf.
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['nrrepurpose'] = [
    'header' => 'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang.xlf:perm.header',
    'items'  => [
        'generate_audio' => [
            'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang.xlf:perm.generate_audio',
            'actions-volume-up',
            'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang.xlf:perm.generate_audio.description',
        ],
        'approve_artifacts' => [
            'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang.xlf:perm.approve_artifacts',
            'actions-check',
            'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang.xlf:perm.approve_artifacts.description',
        ],
        'generate_vision' => [
            'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang.xlf:perm.generate_vision',
            'actions-image',
            'LLL:EXT:nr_repurpose/Resources/Private/Language/locallang.xlf:perm.generate_vision.description',
        ],
    ],
];

// The podcast writes WebVTT subtitles (podcast.vtt). JobFileStorage stores every artifact
// through ResourceStorage::addFile(), which applies the allowed-extensions list that a
// fresh TYPO3 14 installation enforces; "vtt" is not in it (its sibling "srt" is).
$nrRepurposeTextFileExtensions = (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['textfile_ext'] ?? '');
if (!in_array('vtt', array_map(strtolower(...), array_map(trim(...), explode(',', $nrRepurposeTextFileExtensions))), true)) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['textfile_ext'] = ltrim($nrRepurposeTextFileExtensions . ',vtt', ',');
}

unset($nrRepurposeTextFileExtensions);
