<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Provenance;

use Netresearch\NrRepurpose\Provenance\AiProvenance;
use Netresearch\NrRepurpose\Provenance\DigitalSourceType;
use PHPUnit\Framework\TestCase;

final class AiProvenanceTest extends TestCase
{
    public function testMetadataBlockNamesTheKnownModels(): void
    {
        self::assertSame(
            [
                'aiGenerated'       => true,
                'generator'         => 'nr_repurpose 1.2.3',
                'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
                'models'            => ['tts' => 'tts-model-x'],
            ],
            (new AiProvenance('nr_repurpose 1.2.3', DigitalSourceType::TrainedAlgorithmicMedia, ['tts' => 'tts-model-x']))->toArray(),
        );
    }

    public function testMetadataBlockOmitsModelsWhenNoneIsKnown(): void
    {
        self::assertArrayNotHasKey(
            'models',
            (new AiProvenance('nr_repurpose', DigitalSourceType::TrainedAlgorithmicMedia))->toArray(),
        );
    }

    public function testDescriptionIsPrintableAscii(): void
    {
        $description = (new AiProvenance('nr_repurpose', DigitalSourceType::CompositeWithTrainedAlgorithmicMedia, ['image' => "modèl\x01"]))->describe();

        self::assertSame(
            'AI-generated with nr_repurpose (IPTC digital source type: compositeWithTrainedAlgorithmicMedia; models: image=mod??l?).',
            $description,
        );
    }

    public function testTermIsTheLastPathSegment(): void
    {
        self::assertSame('compositeWithTrainedAlgorithmicMedia', DigitalSourceType::CompositeWithTrainedAlgorithmicMedia->term());
    }
}
