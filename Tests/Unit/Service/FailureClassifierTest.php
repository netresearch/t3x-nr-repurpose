<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Service;

use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Provider\Exception\CircuitOpenException;
use Netresearch\NrLlm\Provider\Exception\ProviderAuthenticationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\ProviderRateLimitException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrRepurpose\Domain\Enum\FailureReason;
use Netresearch\NrRepurpose\Exception\PopplerEmptyOutputException;
use Netresearch\NrRepurpose\Generator\Support\InvalidLlmOutputException;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Rendering\RenderingException;
use Netresearch\NrRepurpose\Service\FailureClassifier;
use Netresearch\NrRepurpose\Service\UnusableAnswerException;
use Netresearch\NrRepurpose\Understanding\AnalysisException;
use Netresearch\NrVault\Exception\AccessDeniedException as VaultAccessDeniedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class FailureClassifierTest extends TestCase
{
    /** @param class-string<Throwable> $class */
    private static function without(string $class): Throwable
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /** @return array<string, array{0: Throwable, 1: FailureReason}> */
    public static function failures(): array
    {
        return [
            'no JSON, the record of the failed job' => [new InvalidArgumentException('Failed to decode JSON response: Syntax error', 2805117333), FailureReason::AnswerUnusable],
            'JSON that is no object'                => [new InvalidArgumentException('JSON response must be an object, got string', 2805117334), FailureReason::AnswerUnusable],
            'a structured answer off its schema'    => [new InvalidArgumentException('x', 1784500001), FailureReason::AnswerUnusable],
            'the same on a named configuration'     => [new InvalidArgumentException('x', 1784500002), FailureReason::AnswerUnusable],
            'an argument nr-llm refused'            => [new InvalidArgumentException('temperature', 1), FailureReason::Internal],
            'two unusable answers'                  => [new UnusableAnswerException('x'), FailureReason::AnswerUnusable],
            'a generator refused the answer'        => [new InvalidLlmOutputException('x'), FailureReason::AnswerUnusable],
            'a brief without title'                 => [new AnalysisException('x', 1749384100), FailureReason::AnswerUnusable],
            'an empty document'                     => [new AnalysisException('x', 1749384000), FailureReason::SourceEmpty],
            'too many sections'                     => [new AnalysisException('x', 1749384001), FailureReason::SourceTooLong],
            'a source that cannot be read'          => [new IngestionException('x'), FailureReason::SourceUnreadable],
            'a PDF without text output'             => [new PopplerEmptyOutputException('x'), FailureReason::SourceUnreadable],
            'a render failure'                      => [new RenderingException('x'), FailureReason::Rendering],
            'a timed-out connection'                => [new ProviderConnectionException('x'), FailureReason::ProviderTimeout],
            'too many requests'                     => [self::without(ProviderRateLimitException::class), FailureReason::ProviderRateLimited],
            'a refused key'                         => [self::without(ProviderAuthenticationException::class), FailureReason::ProviderSetup],
            'a provider without a key'              => [new ProviderConfigurationException('x'), FailureReason::ProviderSetup],
            'a feature the model lacks'             => [new UnsupportedFeatureException('x'), FailureReason::ProviderSetup],
            'a missing configuration'               => [new ConfigurationNotFoundException('x'), FailureReason::ProviderSetup],
            'a secret the worker may not read'      => [self::without(VaultAccessDeniedException::class), FailureReason::ProviderSetup],
            'an open circuit breaker'               => [self::without(CircuitOpenException::class), FailureReason::ProviderUnavailable],
            'any other provider error'              => [new ProviderException('x'), FailureReason::ProviderUnavailable],
            'an exhausted budget'                   => [self::without(BudgetExceededException::class), FailureReason::BudgetExhausted],
            'a guardrail stop'                      => [new GuardrailViolationException('Guardrail', 'x'), FailureReason::Guardrail],
            'a guardrail waiting for approval'      => [self::without(GuardrailApprovalRequiredException::class), FailureReason::Guardrail],
            'anything else'                         => [new RuntimeException('x'), FailureReason::Internal],
        ];
    }

    #[DataProvider('failures')]
    public function testAFailureGivesItsReason(Throwable $failure, FailureReason $expected): void
    {
        self::assertSame($expected, FailureClassifier::reasonFor($failure));
    }

    public function testAWrapperIsClassifiedByWhatItWraps(): void
    {
        $wrapped = new RuntimeException('wrapper', 0, new ProviderConnectionException('timed out'));

        self::assertSame(FailureReason::ProviderTimeout, FailureClassifier::reasonFor($wrapped));
    }

    /** The message is anybody's words: a text that names a class changes nothing. */
    public function testTheMessageDecidesNothing(): void
    {
        self::assertSame(FailureReason::Internal, FailureClassifier::reasonFor(new RuntimeException('budget exceeded, rate limit, timeout')));
    }
}
