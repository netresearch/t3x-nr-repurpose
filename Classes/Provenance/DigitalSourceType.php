<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Provenance;

/**
 * The IPTC "Digital Source Type" of an artifact
 * (https://cv.iptc.org/newscodes/digitalsourcetype/), the vocabulary IPTC, C2PA and
 * the major platforms use to declare synthetic media. The value is the full term URI,
 * as the IPTC Photo Metadata Standard requires for Iptc4xmpExt:DigitalSourceType.
 *
 * - trainedAlgorithmicMedia: produced by a generative model as a whole — the podcast
 *   voices, the full AI image, every text format.
 * - compositeWithTrainedAlgorithmicMedia: a composite that contains generative output —
 *   the Schaubild and story renders, which set AI-written copy (and optionally an
 *   AI background) into a human-made branded template.
 */
enum DigitalSourceType: string
{
    case TrainedAlgorithmicMedia = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

    case CompositeWithTrainedAlgorithmicMedia = 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia';

    /** The term without the vocabulary prefix, e.g. "trainedAlgorithmicMedia". */
    public function term(): string
    {
        return substr($this->value, (int) strrpos($this->value, '/') + 1);
    }
}
