<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Ingestion;

use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class PdfFileResolverTest extends AbstractFunctionalTestCase
{
    public function testResolvesFalAttachedPdfToReadableLocalPath(): void
    {
        $storage = $this->get(StorageRepository::class)->getDefaultStorage();
        self::assertNotNull($storage);
        $bytes = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Pdf/sample-text.pdf');
        $folder = $storage->hasFolder('repurpose') ? $storage->getFolder('repurpose') : $storage->createFolder('repurpose');
        $file = $storage->createFile('resolver-test.pdf', $folder);
        $file->setContents($bytes);

        $resolver = $this->get(PdfFileResolver::class);
        $path = $resolver->resolve(['source_type' => 'pdf_fal', 'source_pdf' => $file->getUid()]);

        self::assertFileExists($path);
        self::assertSame($bytes, (string) file_get_contents($path));
    }

    public function testThrowsForFalSourceWithoutFile(): void
    {
        $resolver = $this->get(PdfFileResolver::class);
        $this->expectException(IngestionException::class);
        $resolver->resolve(['source_type' => 'pdf_fal', 'source_pdf' => 0]);
    }
}
