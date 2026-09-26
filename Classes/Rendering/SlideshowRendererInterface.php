<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

/**
 * Turns still images into a silent video: each image slowly zooms in, and neighbouring
 * images cross-fade.
 */
interface SlideshowRendererInterface
{
    /**
     * @param non-empty-list<string> $imagePaths PNG files of $width x $height, in order
     * @param array<string, string>  $metadata   container tags written into the file
     *
     * @return string absolute path of the written MP4
     *
     * @throws RenderingException
     */
    public function render(array $imagePaths, int $width, int $height, float $secondsPerImage, array $metadata): string;
}
