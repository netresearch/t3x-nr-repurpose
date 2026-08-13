<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

interface HtmlToImageRendererInterface
{
    /**
     * @return string absolute path to the produced PNG. $height=null => auto height (fullPage).
     *
     * @throws RenderingException
     */
    public function render(
        string $html,
        int $width,
        ?int $height,
        float $deviceScaleFactor = 1.0,
        bool $transparent = false,
    ): string;
}
