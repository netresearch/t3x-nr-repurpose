<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Exception\ConfigurationInactiveException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Exception\GuardrailPolicyException;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Exception\StructuredResponseMismatchException;
use Netresearch\NrLlm\Provider\Exception\ProviderAuthenticationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\ProviderRateLimitException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrRepurpose\Domain\Enum\FailureReason;
use Netresearch\NrRepurpose\Exception\PopplerEmptyOutputException;
use Netresearch\NrRepurpose\Exception\PopplerProcessFailedException;
use Netresearch\NrRepurpose\Generator\Support\InvalidLlmOutputException;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\Understanding\AnalysisException;
use Netresearch\NrVault\Exception\VaultException;
use Throwable;

/**
 * Turns an exception into the reason the editor reads (ADR-011). The class says it, or a code of
 * this extension and of nr-llm does; the message is never read, because it can be anybody's words.
 * A wrapper with no meaning of its own is classified by the exception it wraps.
 */
final class FailureClassifier
{
    /** AnalysisException codes of DocumentAnalyzer. */
    private const int ANALYSIS_EMPTY_SOURCE = 1749384000;

    private const int ANALYSIS_TOO_LONG = 1749384001;

    /** nr-llm: no JSON (…333), no object (…334), structured answer mismatch (1784500001). */
    private const array UNUSABLE_ANSWER_CODES = [2805117333, 2805117334, 1784500001];

    public static function reasonFor(Throwable $failure): FailureReason
    {
        for ($e = $failure; $e instanceof Throwable; $e = $e->getPrevious()) {
            $reason = self::reasonOf($e);
            if ($reason instanceof FailureReason) {
                return $reason;
            }
        }

        return FailureReason::Internal;
    }

    private static function reasonOf(Throwable $e): ?FailureReason
    {
        return match (true) {
            $e instanceof GuardrailPolicyException => FailureReason::Guardrail,
            $e instanceof BudgetExceededException  => FailureReason::BudgetExhausted,
            $e instanceof AnalysisException        => match ($e->getCode()) {
                self::ANALYSIS_EMPTY_SOURCE => FailureReason::SourceEmpty,
                self::ANALYSIS_TOO_LONG     => FailureReason::SourceTooLong,
                default                     => FailureReason::AnswerUnusable,
            },
            $e instanceof UnusableAnswerException,
            $e instanceof InvalidLlmOutputException,
            $e instanceof StructuredResponseMismatchException                                                    => FailureReason::AnswerUnusable,
            $e instanceof InvalidArgumentException && in_array($e->getCode(), self::UNUSABLE_ANSWER_CODES, true) => FailureReason::AnswerUnusable,
            $e instanceof IngestionException,
            $e instanceof PopplerEmptyOutputException,
            $e instanceof PopplerProcessFailedException => FailureReason::SourceUnreadable,
            $e instanceof RenderingException            => FailureReason::Rendering,
            $e instanceof ProviderRateLimitException    => FailureReason::ProviderRateLimited,
            $e instanceof ProviderConnectionException   => FailureReason::ProviderTimeout,
            $e instanceof ProviderAuthenticationException,
            $e instanceof ProviderConfigurationException,
            $e instanceof UnsupportedFeatureException,
            $e instanceof ConfigurationNotFoundException,
            $e instanceof ConfigurationInactiveException,
            $e instanceof AccessDeniedException,
            $e instanceof VaultException => FailureReason::ProviderSetup,
            // Last among the provider classes: the ones above extend it.
            $e instanceof ProviderException => FailureReason::ProviderUnavailable,
            default                         => null,
        };
    }
}
