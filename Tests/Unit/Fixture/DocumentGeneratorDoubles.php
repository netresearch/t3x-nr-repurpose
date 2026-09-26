<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use Netresearch\NrRepurpose\Provenance\AiProvenance;
use Netresearch\NrRepurpose\Rendering\HtmlToPdfRendererInterface;
use Netresearch\NrRepurpose\Resource\JobFileStorage;
use TYPO3\CMS\Core\Resource\File;

/**
 * A PDF "renderer" that records its calls and writes a small placeholder, and a file
 * storage that records what it was asked to store and answers with a fixed file.
 */
trait DocumentGeneratorDoubles
{
    protected function recordingPdfRenderer(): HtmlToPdfRendererInterface
    {
        return new class implements HtmlToPdfRendererInterface {
            /** @var list<array{html: string, width: int}> */
            public array $calls = [];

            public function renderPdf(string $html, int $viewportWidth): string
            {
                $this->calls[] = ['html' => $html, 'width' => $viewportWidth];
                $out           = sys_get_temp_dir() . '/nrrepurpose_test_' . bin2hex(random_bytes(4)) . '.pdf';
                file_put_contents($out, "%PDF-1.4\n%placeholder\n");

                return $out;
            }
        };
    }

    protected function recordingFileStorage(File $file): JobFileStorage
    {
        return new class ($file) extends JobFileStorage {
            /** @var list<array{content: string, fileName: string, provenance: AiProvenance|null}> */
            public array $stored = [];

            public function __construct(private readonly File $file) {}

            public function store(string $content, string $fileName, ?AiProvenance $provenance = null): File
            {
                $this->stored[] = ['content' => $content, 'fileName' => $fileName, 'provenance' => $provenance];

                return $this->file;
            }
        };
    }
}
