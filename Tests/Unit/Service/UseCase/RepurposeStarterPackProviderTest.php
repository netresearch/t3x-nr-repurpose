<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Service\UseCase;

use Netresearch\NrLlm\Service\UseCase\PackSnippet;
use Netresearch\NrLlm\Service\UseCase\UseCasePack;
use Netresearch\NrRepurpose\Service\Preset\RepurposeConfigurationPresetProvider;
use Netresearch\NrRepurpose\Service\UseCase\RepurposeStarterPackProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The declaration, asserted where it can be wrong.
 *
 * Prose is not tested here — an operator rewrites it. What is tested is the
 * machine-readable half: the tags the job form looks up, the two metadata keys
 * the pipeline reads, and the flag that keeps these snippets out of the text
 * configuration's prompt.
 */
#[CoversClass(RepurposeStarterPackProvider::class)]
final class RepurposeStarterPackProviderTest extends TestCase
{
    private function pack(): UseCasePack
    {
        return (new RepurposeStarterPackProvider())->getPacks()[0];
    }

    /**
     * @return list<PackSnippet>
     */
    private function snippetsTagged(string $tag): array
    {
        return array_values(array_filter(
            $this->pack()->snippets,
            static fn (PackSnippet $snippet): bool => in_array($tag, $snippet->tags, true),
        ));
    }

    /**
     * The five tags JobController::snippetOptions() asks for. A pack that
     * shipped four of them would leave one selector empty and look installed.
     *
     * @return list<array{string}>
     */
    public static function selectorTags(): array
    {
        return [['audience'], ['tone_of_voice'], ['persona'], ['layout'], ['style']];
    }

    #[Test]
    #[DataProvider('selectorTags')]
    public function everySelectorInTheJobFormHasSomethingToOffer(string $tag): void
    {
        self::assertNotSame(
            [],
            $this->snippetsTagged($tag),
            sprintf('The "%s" selector would still read "(none)" after installing this pack.', $tag),
        );
    }

    #[Test]
    public function noSnippetIsLinkedToTheConfigurationByTag(): void
    {
        // The defect this guards: linking these tags makes nr-llm compose EVERY
        // active snippet carrying them into every completion on the text
        // configuration, on top of the per-job selection (nr-llm ADR-186).
        self::assertSame([], $this->pack()->getSnippetTags());
    }

    #[Test]
    public function everyPersonaCarriesTheVoiceThePipelineReads(): void
    {
        $personas = $this->snippetsTagged('persona');
        self::assertNotSame([], $personas);

        foreach ($personas as $persona) {
            $voice = $persona->metadata['voice'] ?? null;
            self::assertIsString($voice, $persona->identifier . ' declares no voice.');
            self::assertNotSame('', $voice, $persona->identifier . ' declares an empty voice.');
        }
    }

    #[Test]
    public function everyLayoutCarriesAnImageSizeTheGeneratorAccepts(): void
    {
        // Mirrors AbstractGenerator::resolveImageSize(): divisible by 16, at
        // most 3840x2160, aspect within 1:3..3:1. A value outside that is not
        // an error — the generator logs a warning and silently uses its own
        // default, so the layout would be a no-op nobody notices.
        $layouts = $this->snippetsTagged('layout');
        self::assertNotSame([], $layouts);

        foreach ($layouts as $layout) {
            $size = $layout->metadata['imageSize'] ?? null;
            self::assertIsString($size, $layout->identifier . ' declares no imageSize.');
            self::assertSame(
                1,
                preg_match('/^(\d{2,4})x(\d{2,4})$/', $size, $matches),
                $layout->identifier . ' declares imageSize "' . $size . '", which is not WxH.',
            );

            $width  = (int) $matches[1];
            $height = (int) $matches[2];
            $why    = $layout->identifier . ' would fall back to the generator default.';

            self::assertSame(0, $width % 16, $why);
            self::assertSame(0, $height % 16, $why);
            self::assertLessThanOrEqual(3840, $width, $why);
            self::assertLessThanOrEqual(2160, $height, $why);
            self::assertLessThanOrEqual(3 * $height, $width, $why);
            self::assertLessThanOrEqual(3 * $width, $height, $why);
        }
    }

    #[Test]
    public function thePackInstallsTheTextConfigurationTheExtensionAlreadyDeclares(): void
    {
        // One preset object, not a second hand-written copy of the identifier.
        self::assertSame(
            RepurposeConfigurationPresetProvider::TEXT_CONFIGURATION,
            $this->pack()->configurationPreset->identifier,
        );
    }

    #[Test]
    public function thePackDeclaresNoTasks(): void
    {
        // This extension runs its own pipeline; a Task record would put a
        // button in the Tasks module that does none of what the pack is for.
        self::assertSame([], $this->pack()->tasks);
    }
}
