<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

interface ImageCompositorInterface
{
    /**
     * Overlays $foregroundPng (transparent) onto $backgroundPng (GD; Imagick is NOT installed).
     *
     * @return string absolute path of the composited PNG ($outPath)
     *
     * @throws RenderingException
     */
    public function overlay(string $backgroundPng, string $foregroundPng, string $outPath): string;
}
