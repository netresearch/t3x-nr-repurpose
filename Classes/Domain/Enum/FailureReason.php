<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Enum;

/**
 * Why a job or an artifact failed, as the editor reads it (ADR-011). The value is stored with the
 * failure and is the code an editor passes on. Each case has one fixed text per PARTS entry in
 * locallang.xlf ("failure.<value>.<part>"): no provider text, path or SQL reaches these columns
 * or those texts; the exception stays in the TYPO3 log.
 */
enum FailureReason: string
{
    case SourceUnreadable    = 'source_unreadable';
    case SourceEmpty         = 'source_empty';
    case SourceTooLong       = 'source_too_long';
    case AnswerUnusable      = 'answer_unusable';
    case ProviderTimeout     = 'provider_timeout';
    case ProviderRateLimited = 'provider_rate_limited';
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderSetup       = 'provider_setup';
    case BudgetExhausted     = 'budget_exhausted';
    case BudgetOrService     = 'budget_or_service';
    case Guardrail           = 'guardrail';
    case NotPermitted        = 'not_permitted';
    case Rendering           = 'rendering';
    case SomeFormatsFailed   = 'some_formats_failed';
    case AllFormatsFailed    = 'all_formats_failed';
    case Internal            = 'internal';
    case WorkerStalled       = 'worker_stalled';
    case Unknown             = 'unknown';

    /**
     * The texts every case has: the line in the job list, then what happened, what is left, what
     * the editor can do, and when to ask whom.
     */
    public const array PARTS = ['short', 'what', 'result', 'action', 'admin'];

    /**
     * A failure stored before this column existed has only its fixed text. The job-level texts
     * still say what happened; anything else becomes Unknown and the stored text stays unread,
     * because rows of older versions can hold a provider's words.
     */
    public static function fromStored(string $stored, string $legacyMessage): self
    {
        $reason = self::tryFrom($stored);
        if ($reason instanceof self) {
            return $reason;
        }

        return match (true) {
            preg_match('/^\d+ of \d+ formats failed/', $legacyMessage) === 1 => self::SomeFormatsFailed,
            preg_match('/^All \d+ formats failed/', $legacyMessage) === 1    => self::AllFormatsFailed,
            default                                                          => self::Unknown,
        };
    }

    /** A new run can end differently; the texts of the other cases name another way forward. */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::SourceEmpty, self::SourceTooLong, self::Guardrail, self::SomeFormatsFailed, self::WorkerStalled => false,
            default                                                                                               => true,
        };
    }

    /**
     * These stored messages are written for the editor: a fixed text naming their own source URL
     * without credentials, or the guardrail's reason. No other case shows its stored text.
     */
    public function isStoredMessageShown(): bool
    {
        return $this === self::SourceUnreadable || $this === self::Guardrail;
    }
}
