<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Psr\Log\LoggerInterface;

/**
 * Routes the extension's plain-text completions to the "nr_repurpose_text"
 * nr-llm Configuration record, so text generation targets its own named
 * configuration exactly like image generation ("nr_repurpose_image") and
 * speech ("nr_repurpose_tts") do — provider, model, system prompt, budget and
 * cost attribution all steer from that one record.
 *
 * It decorates nr-llm's CompletionService (nr-llm 0.22+ / ADR-077): the five
 * plain methods resolve the named configuration and dispatch through the
 * matching `*ForConfiguration()` entry point; the `*ForConfiguration()` methods
 * pass straight through (the caller already named a configuration). Per-call
 * budget metadata on the options is preserved on the configuration path.
 *
 * Fail-soft: when the "nr_repurpose_text" record is not imported (or inactive,
 * or access-restricted in a user-less worker context) resolution falls back to
 * the instance-default configuration — the pre-0.22 behaviour — so an
 * unconfigured install keeps working. Wired into the generators via a scoped
 * `$completion` bind in Configuration/Services.yaml.
 */
final class ConfiguredCompletionService implements CompletionServiceInterface
{
    /** The nr-llm Configuration record (identifier) steering text generation. */
    public const CONFIGURATION = 'nr_repurpose_text';

    /** Resolved configuration, memoized once per instance (null = fall back to default). */
    private ?LlmConfiguration $configuration = null;

    private bool $resolved = false;

    /**
     * @param CompletionServiceInterface $inner the real nr-llm completion service; the distinct
     *                                          parameter name (not `$completion`) keeps the
     *                                          Services.yaml `$completion` bind from recursing here
     */
    public function __construct(
        private readonly CompletionServiceInterface $inner,
        private readonly ConfigurationResolver $configurationResolver,
        private readonly LoggerInterface $logger,
    ) {}

    public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $configuration = $this->resolveConfiguration();

        return $configuration !== null
            ? $this->inner->completeForConfiguration($prompt, $configuration, $options)
            : $this->inner->complete($prompt, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function completeJson(string $prompt, ?ChatOptions $options = null): array
    {
        $configuration = $this->resolveConfiguration();

        return $configuration !== null
            ? $this->inner->completeJsonForConfiguration($prompt, $configuration, $options)
            : $this->inner->completeJson($prompt, $options);
    }

    public function completeMarkdown(string $prompt, ?ChatOptions $options = null): string
    {
        $configuration = $this->resolveConfiguration();

        return $configuration !== null
            ? $this->inner->completeMarkdownForConfiguration($prompt, $configuration, $options)
            : $this->inner->completeMarkdown($prompt, $options);
    }

    public function completeFactual(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $configuration = $this->resolveConfiguration();

        return $configuration !== null
            ? $this->inner->completeFactualForConfiguration($prompt, $configuration, $options)
            : $this->inner->completeFactual($prompt, $options);
    }

    public function completeCreative(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $configuration = $this->resolveConfiguration();

        return $configuration !== null
            ? $this->inner->completeCreativeForConfiguration($prompt, $configuration, $options)
            : $this->inner->completeCreative($prompt, $options);
    }

    public function completeForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse
    {
        return $this->inner->completeForConfiguration($prompt, $configuration, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function completeJsonForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): array
    {
        return $this->inner->completeJsonForConfiguration($prompt, $configuration, $options);
    }

    public function completeMarkdownForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): string
    {
        return $this->inner->completeMarkdownForConfiguration($prompt, $configuration, $options);
    }

    public function completeFactualForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse
    {
        return $this->inner->completeFactualForConfiguration($prompt, $configuration, $options);
    }

    public function completeCreativeForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse
    {
        return $this->inner->completeCreativeForConfiguration($prompt, $configuration, $options);
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
            $this->configuration = $this->configurationResolver->getActiveByIdentifier(self::CONFIGURATION);
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
