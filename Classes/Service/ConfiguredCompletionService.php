<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\StructuredCompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ConfigurationIdentifier;
use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Psr\Log\LoggerInterface;

/**
 * Routes the extension's text completions to the "nr_repurpose_text" nr-llm
 * Configuration record, so text generation targets its own named
 * configuration exactly like image generation ("nr_repurpose_image") and
 * speech ("nr_repurpose_tts") do — provider, model, system prompt, budget and
 * cost attribution all steer from that one record.
 *
 * It calls nr-llm's CompletionService (nr-llm 0.22+ / ADR-077): each method
 * resolves the named configuration and dispatches through the matching
 * `*ForConfiguration()` entry point. Per-call budget metadata on the options
 * is preserved on the configuration path. It implements the extension's own
 * TextCompletionInterface, not nr-llm's interface, so it loads with nr-llm
 * 0.38 and 0.39 alike; the one return shape that differs between them, the
 * structured answer, is unwrapped here.
 *
 * Fail-soft: when the "nr_repurpose_text" record is not imported (or inactive,
 * or access-restricted in a user-less worker context) resolution falls back to
 * the instance-default configuration — the pre-0.22 behaviour — so an
 * unconfigured install keeps working. Wired into the generators through the
 * TextCompletionInterface alias in Configuration/Services.yaml.
 *
 * Every text completion in this extension passes through here, so this is also
 * where the caller identity (nr-llm ADR-177) is guaranteed: options that already
 * name a caller are passed through untouched — the operation belongs to the call
 * site, not here — and options that name none are stamped with the extension key,
 * so a new call site cannot land in the Analytics "Unattributed" bucket.
 */
final class ConfiguredCompletionService implements TextCompletionInterface
{
    /** The nr-llm Configuration record (identifier) steering text generation. */
    public const string CONFIGURATION = 'nr_repurpose_text';

    /** Resolved configuration, memoized once per instance (null = fall back to default). */
    private ?LlmConfiguration $configuration = null;

    private bool $resolved = false;

    /**
     * @param CompletionServiceInterface $inner the real nr-llm completion service
     */
    public function __construct(
        private readonly CompletionServiceInterface $inner,
        private readonly ConfigurationResolver $configurationResolver,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function completeJson(string $prompt, ?ChatOptions $options = null): array
    {
        $options       = $this->withCallerIdentity($options);
        $configuration = $this->resolveConfiguration();

        return $configuration instanceof LlmConfiguration
            ? $this->inner->completeJsonForConfiguration($prompt, $configuration, $options)
            : $this->inner->completeJson($prompt, $options);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public function completeStructured(string $prompt, array $schema, ?ChatOptions $options = null): array
    {
        $options       = $this->withCallerIdentity($options);
        $configuration = $this->resolveConfiguration();

        return $this->structuredData($configuration instanceof LlmConfiguration
            ? $this->inner->completeStructuredForConfiguration($prompt, $configuration, $schema, $options)
            : $this->inner->completeStructured($prompt, $schema, $options));
    }

    public function completeMarkdown(string $prompt, ?ChatOptions $options = null): string
    {
        $options       = $this->withCallerIdentity($options);
        $configuration = $this->resolveConfiguration();

        return $configuration instanceof LlmConfiguration
            ? $this->inner->completeMarkdownForConfiguration($prompt, $configuration, $options)
            : $this->inner->completeMarkdown($prompt, $options);
    }

    /**
     * The decoded answer of a structured completion. nr-llm up to 0.38 returns
     * it as the array itself; 0.39 returns a StructuredCompletionResponse whose
     * `data` it is (ADR-211). The parameter is `mixed` because the declared
     * type depends on the installed nr-llm version. `instanceof` does not
     * autoload, so the check runs on 0.38, where the class does not exist.
     *
     * @return array<string, mixed>
     */
    private function structuredData(mixed $result): array
    {
        if ($result instanceof StructuredCompletionResponse) {
            return $result->data;
        }

        /** @var array<string, mixed> $result nr-llm < 0.39 declares this shape */
        return $result;
    }

    /**
     * Guarantee the call names this extension (nr-llm ADR-177). A caller that
     * already set one keeps it, operation included — the operation identifies the
     * pipeline step and only the call site knows it. A caller that set none (or
     * passed no options at all) gets the bare extension key: attributed to
     * nr_repurpose with an empty operation, rather than "Unattributed".
     */
    private function withCallerIdentity(?ChatOptions $options): ChatOptions
    {
        $options ??= new ChatOptions();
        $extension = $options->getCallerSourceExtension();

        return $extension === null || $extension === ''
            ? $options->withCallerSource(CallerSource::EXTENSION)
            : $options;
    }

    /**
     * Resolve the "nr_repurpose_text" configuration once, or null to fall back
     * to the instance default. Any nr-llm resolution failure (not imported,
     * inactive, access-restricted) is a fail-soft fallback, not an error.
     */
    private function resolveConfiguration(): ?LlmConfiguration
    {
        if ($this->resolved) {
            return $this->configuration;
        }

        $this->resolved = true;

        try {
            // A ConfigurationIdentifier, not the bare string: nr-llm 0.35 narrowed
            // this parameter to the value object (#893, second step). A string
            // reaches it as a TypeError, which is NOT an NrLlmExceptionInterface
            // — so the fail-soft catch below would not have caught it and the
            // whole text pipeline would have died instead of falling back.
            $this->configuration = $this->configurationResolver->getActiveByIdentifier(
                new ConfigurationIdentifier(self::CONFIGURATION),
            );
        } catch (NrLlmExceptionInterface $e) {
            $this->logger->debug(
                'nr_repurpose: "{identifier}" configuration not resolvable ({reason}); text generation falls back to the instance-default configuration.',
                ['identifier' => self::CONFIGURATION, 'reason' => $e->getMessage()],
            );
            $this->configuration = null;
        }

        return $this->configuration;
    }
}
