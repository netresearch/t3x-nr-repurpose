<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Generator\Support;

/**
 * One spoken turn of the two-host podcast dialogue. The voice is resolved by the
 * generator from the speaker label (Host A => nova, Host B => onyx by default).
 */
final readonly class DialogueTurn
{
    public function __construct(
        public string $speaker,
        public string $text,
        public string $voice,
    ) {}
}
