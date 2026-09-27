<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * ES module import map configuration.
 *
 * Maps the extension's module specifiers to Resources/Public/JavaScript/, so a
 * controller can load them with PageRenderer::loadJavaScriptModule(). Scripts go
 * through modules rather than inline <script> blocks because the backend Content
 * Security Policy refuses inline scripts without a nonce.
 *
 * @see https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Backend/JavaScript/ES6/Index.html
 */
return [
    'dependencies' => [
        'backend',
    ],
    'imports' => [
        '@netresearch/nr-repurpose/' => 'EXT:nr_repurpose/Resources/Public/JavaScript/',
    ],
];
