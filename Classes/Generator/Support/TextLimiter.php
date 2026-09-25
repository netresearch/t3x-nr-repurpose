<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Support;

use InvalidArgumentException;

/**
 * Cuts a text to a character limit without leaving half a sentence behind.
 *
 * Characters are Unicode code points (mb_strlen). That is what LinkedIn and Instagram
 * count; X weighs some characters double (e.g. most emoji and CJK), so a 280-code-point
 * post can still exceed X's weighted limit in those scripts.
 */
final class TextLimiter
{
    private const ELLIPSIS = '…';

    /**
     * Return $text unchanged when it fits. Otherwise cut it at the last sentence end
     * (".", "!", "?" or "…" followed by whitespace) inside the limit; when the first
     * sentence alone is too long, cut at the last word boundary and append "…". The
     * result never exceeds $maxChars.
     */
    public function truncate(string $text, int $maxChars): string
    {
        if ($maxChars < 2) {
            throw new InvalidArgumentException('The character limit must be at least 2', 1790000001);
        }

        $text = trim($text);
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        // Look one character past the limit: a sentence end exactly AT the limit is
        // followed by whitespace that is not part of the kept text.
        $window = mb_substr($text, 0, $maxChars + 1);
        preg_match_all('/[.!?…](?=\s)/u', $window, $matches, PREG_OFFSET_CAPTURE);
        foreach (array_reverse($matches[0]) as [$mark, $byteOffset]) {
            $cut = rtrim(substr($window, 0, $byteOffset + strlen($mark)));
            if ($cut !== '' && mb_strlen($cut) <= $maxChars) {
                return $cut;
            }
        }

        $head      = mb_substr($text, 0, $maxChars - 1);
        $lastSpace = mb_strrpos($head, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            $head = mb_substr($head, 0, $lastSpace);
        }

        return rtrim($head, " \t\n\r,;:-") . self::ELLIPSIS;
    }
}
