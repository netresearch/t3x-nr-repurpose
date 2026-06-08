<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Rendering;

/**
 * Raised by render-infra primitives (HTML→PNG rendering, image compositing, audio
 * stitching) when an underlying tool (chromium/ffmpeg/ffprobe/GD) or its inputs fail.
 * Generators (Plan 5) catch this to mark a single artifact failed without aborting siblings.
 */
final class RenderingException extends \RuntimeException
{
    public static function because(string $message, int $code, ?\Throwable $previous = null): self
    {
        return new self($message, $code, $previous);
    }
}
