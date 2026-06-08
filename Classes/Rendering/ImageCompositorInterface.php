<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

interface ImageCompositorInterface
{
    /**
     * Overlays $foregroundPng (transparent) onto $backgroundPng (GD; Imagick is NOT installed).
     *
     * @return string absolute path of the composited PNG ($outPath)
     * @throws RenderingException
     */
    public function overlay(string $backgroundPng, string $foregroundPng, string $outPath): string;
}
