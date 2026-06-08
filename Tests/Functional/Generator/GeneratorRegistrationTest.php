<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Generator;

use Netresearch\NrRepurpose\Generator\ArtifactGeneratorInterface;
use Netresearch\NrRepurpose\Generator\PodcastGenerator;
use Netresearch\NrRepurpose\Generator\SchaubildGenerator;
use Netresearch\NrRepurpose\Generator\StoryGenerator;
use Netresearch\NrRepurpose\Generator\StubArtifactGenerator;
use Netresearch\NrRepurpose\Service\GenerationOrchestratorInterface;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;

final class GeneratorRegistrationTest extends AbstractFunctionalTestCase
{
    public function testThreeRealGeneratorsAreAutowirable(): void
    {
        self::assertInstanceOf(ArtifactGeneratorInterface::class, $this->get(PodcastGenerator::class));
        self::assertInstanceOf(ArtifactGeneratorInterface::class, $this->get(SchaubildGenerator::class));
        self::assertInstanceOf(ArtifactGeneratorInterface::class, $this->get(StoryGenerator::class));
    }

    public function testOrchestratorReceivesTheThreeRealGeneratorsButNotTheStub(): void
    {
        $orchestrator = $this->get(GenerationOrchestratorInterface::class);

        $prop = (new \ReflectionClass($orchestrator))->getProperty('generators');
        $prop->setAccessible(true);
        /** @var list<ArtifactGeneratorInterface> $generators */
        $generators = $prop->getValue($orchestrator);

        $classes = array_map(static fn (ArtifactGeneratorInterface $g): string => $g::class, $generators);

        self::assertContains(PodcastGenerator::class, $classes);
        self::assertContains(SchaubildGenerator::class, $classes);
        self::assertContains(StoryGenerator::class, $classes);
        self::assertNotContains(StubArtifactGenerator::class, $classes);
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
