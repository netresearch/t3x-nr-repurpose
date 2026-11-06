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
use Netresearch\NrLlm\Provider\Exception\ProviderAuthenticationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\ProviderRateLimitException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceQuotaExceededException;
use Netresearch\NrLlm\Specialized\Exception\SpecializedServiceException;
use Netresearch\NrRepurpose\Domain\Enum\FailureReason;
use Netresearch\NrRepurpose\Exception\PopplerEmptyOutputException;
use Netresearch\NrRepurpose\Exception\PopplerProcessFailedException;
use Netresearch\NrRepurpose\Generator\Support\InvalidLlmOutputException;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\SourceReview\SourceReviewRejectedException;
use Netresearch\NrRepurpose\Understanding\AnalysisException;
use Netresearch\NrVault\Exception\VaultException;
use Throwable;

/**
 * Turns an exception into the reason the editor reads (ADR-011). The class says it, or a code of
 * this extension and of nr-llm does; the message is never read, because it can be anybody's words.
 * A wrapper is classified by the exception it wraps: an image or speech call ends as a
 * RenderingException, which means a render failure only when nothing below it says otherwise.
 */
final class FailureClassifier
{
    /** AnalysisException codes of DocumentAnalyzer. */
    private const int ANALYSIS_EMPTY_SOURCE = 1749384000;

    private const int ANALYSIS_TOO_LONG = 1749384001;

    /** IngestionException codes: a PDF without text, with image reading refused, with too many pages. */
    private const int INGESTION_NO_TEXT = 1749379452;

    private const int INGESTION_IMAGES_REFUSED = 1749379453;

    private const int INGESTION_TOO_MANY_PAGES = 1749379454;

    /** nr-llm: no JSON (…333), no object (…334), a structured answer off its schema (…001, …002). */
    private const array UNUSABLE_ANSWER_CODES = [2805117333, 2805117334, 1784500001, 1784500002];

    /** nr-llm: no text at the output limit (OutputBudgetExhaustedException since nr-llm 0.43.2), checked by code. */
    private const int OUTPUT_BUDGET_EXHAUSTED = 2041730951;

    public static function reasonFor(Throwable $failure): FailureReason
    {
        $found = null;
        for ($e = $failure; $e instanceof Throwable; $e = $e->getPrevious()) {
            $reason = self::reasonOf($e);
            if ($reason instanceof FailureReason && $reason !== FailureReason::Rendering) {
                return $reason;
            }

            $found ??= $reason;
        }

        return $found ?? FailureReason::Internal;
    }

    private static function reasonOf(Throwable $e): ?FailureReason
    {
        return match (true) {
            $e instanceof SourceReviewRejectedException => FailureReason::SourceReview,
            $e instanceof GuardrailPolicyException      => FailureReason::Guardrail,
            $e instanceof BudgetExceededException       => FailureReason::BudgetExhausted,
            $e instanceof AnalysisException             => match ($e->getCode()) {
                self::ANALYSIS_EMPTY_SOURCE => FailureReason::SourceEmpty,
                self::ANALYSIS_TOO_LONG     => FailureReason::SourceTooLong,
                default                     => FailureReason::AnswerUnusable,
            },
            $e instanceof UnusableAnswerException, $e instanceof InvalidLlmOutputException                                                                                                           => FailureReason::AnswerUnusable,
            $e instanceof InvalidArgumentException && in_array($e->getCode(), self::UNUSABLE_ANSWER_CODES, true), $e instanceof ProviderException && $e->getCode() === self::OUTPUT_BUDGET_EXHAUSTED => FailureReason::AnswerUnusable,
            $e instanceof IngestionException                                                                                                                                                         => match ($e->getCode()) {
                self::INGESTION_NO_TEXT, self::INGESTION_IMAGES_REFUSED => FailureReason::SourceEmpty,
                self::INGESTION_TOO_MANY_PAGES                          => FailureReason::SourceTooLong,
                default                                                 => FailureReason::SourceUnreadable,
            },
            $e instanceof PopplerEmptyOutputException, $e instanceof PopplerProcessFailedException                                                                                                                                                                                                                                                             => FailureReason::SourceUnreadable,
            $e instanceof RenderingException                                                                                                                                                                                                                                                                                                                   => FailureReason::Rendering,
            $e instanceof ProviderRateLimitException                                                                                                                                                                                                                                                                                                           => FailureReason::ProviderRateLimited,
            $e instanceof ProviderConnectionException                                                                                                                                                                                                                                                                                                          => FailureReason::ProviderTimeout,
            $e instanceof ProviderAuthenticationException, $e instanceof ProviderConfigurationException, $e instanceof UnsupportedFeatureException, $e instanceof ConfigurationNotFoundException, $e instanceof ConfigurationInactiveException, $e instanceof AccessDeniedException, $e instanceof ServiceConfigurationException, $e instanceof VaultException => FailureReason::ProviderSetup,
            $e instanceof ServiceQuotaExceededException                                                                                                                                                                                                                                                                                                        => FailureReason::ProviderRateLimited,
            // Last among the provider classes: the ones above extend it.
            $e instanceof ProviderException, $e instanceof SpecializedServiceException => FailureReason::ProviderUnavailable,
            default                                                                    => null,
        };
    }
}
