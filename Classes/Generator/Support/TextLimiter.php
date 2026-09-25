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
 * post can still exceed X's weighted limit in those scripts. Whitespace is any Unicode
 * whitespace or separator (space, newline, no-break space, …).
 */
final class TextLimiter
{
    private const ELLIPSIS = '…';

    // Any Unicode whitespace: PHP compiles /u patterns with Unicode properties (UCP), so
    // \s also matches newline, no-break space (U+00A0) and the other separators.
    private const WHITESPACE = '\s';

    /** The cut text only; see cut() for the rules. */
    public function truncate(string $text, int $maxChars): string
    {
        return $this->cut($text, $maxChars)->text;
    }

    /**
     * Return $text unchanged when it fits. Otherwise cut it at the last sentence end
     * (".", "!", "?" or "…" followed by whitespace) inside the limit — but only when that
     * keeps at least half the limit: a post must not shrink to its opening exclamation.
     * Otherwise cut at the last word boundary and append "…". The result never exceeds
     * $maxChars.
     */
    public function cut(string $text, int $maxChars): TextCut
    {
        if ($maxChars < 2) {
            throw new InvalidArgumentException('The character limit must be at least 2', 1790000001);
        }

        $text = trim($text);
        if (mb_strlen($text) <= $maxChars) {
            return new TextCut($text, TextCut::NONE);
        }

        // Look one character past the limit: a sentence end exactly AT the limit is
        // followed by whitespace that is not part of the kept text.
        $window = mb_substr($text, 0, $maxChars + 1);
        preg_match_all('/[.!?…](?=' . self::WHITESPACE . ')/u', $window, $matches, PREG_OFFSET_CAPTURE);
        foreach (array_reverse($matches[0]) as [$mark, $byteOffset]) {
            $cut = rtrim(substr($window, 0, $byteOffset + strlen($mark)));
            if (mb_strlen($cut) > $maxChars) {
                continue;
            }

            // The last fitting sentence end is the longest cut; an earlier one is shorter.
            if ($cut !== '' && mb_strlen($cut) * 2 >= $maxChars) {
                return new TextCut($cut, TextCut::SENTENCE);
            }

            break;
        }

        $head = mb_substr($text, 0, $maxChars - 1);
        preg_match_all('/' . self::WHITESPACE . '/u', $head, $spaces, PREG_OFFSET_CAPTURE);
        $lastSpace = end($spaces[0]);
        if ($lastSpace !== false && $lastSpace[1] > 0) {
            $head = substr($head, 0, $lastSpace[1]);
        }

        return new TextCut(preg_replace('/(?:' . self::WHITESPACE . '|[,;:\-])+$/u', '', $head) . self::ELLIPSIS, TextCut::WORD);
    }
}
