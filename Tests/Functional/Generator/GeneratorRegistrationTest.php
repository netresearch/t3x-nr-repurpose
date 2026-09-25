<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Generator;

use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Generator\ExecutiveSummaryGenerator;
use Netresearch\NrRepurpose\Generator\FaqGenerator;
use Netresearch\NrRepurpose\Generator\NewsletterGenerator;
use Netresearch\NrRepurpose\Generator\PodcastGenerator;
use Netresearch\NrRepurpose\Generator\SchaubildGenerator;
use Netresearch\NrRepurpose\Generator\SocialPostGenerator;
use Netresearch\NrRepurpose\Generator\StoryGenerator;
use Netresearch\NrRepurpose\Generator\StubArtifactGenerator;
use Netresearch\NrRepurpose\Service\ConfiguredCompletionService;
use Netresearch\NrRepurpose\Service\GenerationOrchestratorInterface;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use ReflectionClass;
use ReflectionProperty;

final class GeneratorRegistrationTest extends AbstractFunctionalTestCase
{
    /** @return list<class-string<ArtifactGeneratorInterface>> */
    private function realGenerators(): array
    {
        return [
            PodcastGenerator::class,
            SchaubildGenerator::class,
            StoryGenerator::class,
            ExecutiveSummaryGenerator::class,
            FaqGenerator::class,
            SocialPostGenerator::class,
            NewsletterGenerator::class,
        ];
    }

    public function testTheRealGeneratorsAreAutowirable(): void
    {
        foreach ($this->realGenerators() as $class) {
            self::assertInstanceOf(ArtifactGeneratorInterface::class, $this->get($class), $class);
        }
    }

    public function testOrchestratorReceivesTheRealGeneratorsButNotTheStub(): void
    {
        $orchestrator = $this->get(GenerationOrchestratorInterface::class);

        $prop = (new ReflectionClass($orchestrator))->getProperty('generators');
        /** @var list<ArtifactGeneratorInterface> $generators */
        $generators = $prop->getValue($orchestrator);

        $classes = array_map(static fn (ArtifactGeneratorInterface $g): string => $g::class, $generators);

        foreach ($this->realGenerators() as $class) {
            self::assertContains($class, $classes);
        }

        self::assertNotContains(StubArtifactGenerator::class, $classes);
    }

    /**
     * The text generators receive the completion service through the abstract base's
     * constructor; Services.yaml routes it to the nr_repurpose_text configuration only
     * when the parameter is named $completion. A rename would silently fall back to
     * nr-llm's unconfigured service.
     */
    public function testTheTextGeneratorsUseTheConfiguredCompletionService(): void
    {
        foreach ([ExecutiveSummaryGenerator::class, FaqGenerator::class, SocialPostGenerator::class, NewsletterGenerator::class] as $class) {
            $completion = (new ReflectionProperty($class, 'completion'))->getValue($this->get($class));
            self::assertInstanceOf(ConfiguredCompletionService::class, $completion, $class);
        }
    }

    public function testCapabilityPermOptionsAreRegistered(): void
    {
        $options = $GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['nrrepurpose'] ?? null;

        self::assertIsArray($options);
        self::assertArrayHasKey('items', $options);
        self::assertArrayHasKey('generate_audio', $options['items']);
        self::assertArrayHasKey('generate_vision', $options['items']);
    }
}
