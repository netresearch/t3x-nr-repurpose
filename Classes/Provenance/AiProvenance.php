<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Provenance;

/**
 * The AI-origin statement of one artifact (EU AI Act Art. 50(2)): who generated it, what
 * kind of synthetic media it is and — where this extension knows it — which models ran.
 * One value feeds every marker: the `aiLabel` block of the artifact metadata, the PNG
 * text chunks and XMP packet, the MP3 ID3 frames and the FAL file description (ADR-005).
 */
final readonly class AiProvenance
{
    /**
     * @param string                $generator e.g. "nr_repurpose 0.6.0" (version when known)
     * @param array<string, string> $models    role => model id, e.g. ['tts' => 'gpt-4o-mini-tts'];
     *                                         only models that are actually known
     */
    public function __construct(
        public string $generator,
        public DigitalSourceType $sourceType,
        public array $models = [],
    ) {}

    /**
     * The `aiLabel` block stored in the artifact metadata JSON. `models` is omitted when
     * no model is known (text formats: nr-llm does not report the completion model).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $label = [
            'aiGenerated'       => true,
            'generator'         => $this->generator,
            'digitalSourceType' => $this->sourceType->value,
        ];
        if ($this->models !== []) {
            $label['models'] = $this->models;
        }

        return $label;
    }

    /**
     * One sentence for the human-readable marker fields that take UTF-8: the XMP
     * dc:description, the WebVTT NOTE block and sys_file_metadata.description.
     */
    public function describe(): string
    {
        $models = '';
        if ($this->models !== []) {
            $models = '; models: ' . implode(', ', array_map(
                static fn (string $role, string $model): string => $role . '=' . $model,
                array_keys($this->models),
                $this->models,
            ));
        }

        return sprintf(
            'AI-generated with %s (IPTC digital source type: %s%s).',
            $this->generator,
            $this->sourceType->term(),
            $models,
        );
    }

    /** describe() for the fields written as ISO-8859-1 / Latin-1 (ID3 COMM, PNG tEXt Comment). */
    public function describeAscii(): string
    {
        return self::ascii($this->describe());
    }

    /**
     * Printable ASCII only, for the ID3 frames (ISO-8859-1) and PNG tEXt chunks
     * (Latin-1). Letters are transliterated ("modèl" -> "model") with intl, which
     * TYPO3 core requires; whatever has no ASCII form, and control characters,
     * become "?" rather than being mis-encoded.
     */
    public static function ascii(string $value): string
    {
        $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);

        return (string) preg_replace('/[^\x20-\x7E]/', '?', is_string($transliterated) ? $transliterated : $value);
    }
}
