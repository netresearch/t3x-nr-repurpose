<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Ingestion;

use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class PdfFileResolverTest extends AbstractFunctionalTestCase
{
    public function testResolvesFalAttachedPdfToReadableLocalPath(): void
    {
        $storage = $this->get(StorageRepository::class)->getDefaultStorage();
        self::assertNotNull($storage);
        $bytes  = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Pdf/sample-text.pdf');
        $folder = $storage->hasFolder('repurpose') ? $storage->getFolder('repurpose') : $storage->createFolder('repurpose');
        $file   = $storage->createFile('resolver-test.pdf', $folder);
        $file->setContents($bytes);

        $this->attachToJob(3, $file->getUid());

        $resolver = $this->get(PdfFileResolver::class);
        $path     = $resolver->resolve(['uid' => 3, 'source_type' => 'pdf_fal', 'source_pdf' => 1]);

        self::assertFileExists($path);
        self::assertSame($bytes, (string) file_get_contents($path));

        // The local driver hands out the stored file itself, not a transient copy, and
        // releasing it after the read must leave the editor's file in place.
        self::assertStringNotContainsString('/transient/', $path);
        $resolver->release($path);
        self::assertFileExists($path);
        self::assertSame($bytes, $file->getContents());
    }

    /**
     * The List module stores a type=file field the way DataHandler does: the job's
     * column holds the number of references (1), and the file hangs off a
     * sys_file_reference row. sys_file uid 1 is a different file here, so a resolver
     * that reads the column as a file uid picks the wrong one.
     */
    public function testResolvesTheFileTheJobReferencesNotTheFileWithTheColumnValueAsUid(): void
    {
        $storage = $this->get(StorageRepository::class)->getDefaultStorage();
        self::assertNotNull($storage);
        $folder = $storage->hasFolder('repurpose') ? $storage->getFolder('repurpose') : $storage->createFolder('repurpose');

        $other = $storage->createFile('other.pdf', $folder);
        $other->setContents('%PDF-1.4 not the attached file');

        $attachedBytes = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Pdf/sample-text.pdf');
        $attached      = $storage->createFile('attached.pdf', $folder);
        $attached->setContents($attachedBytes);
        self::assertSame(1, $other->getUid(), 'sys_file uid 1 must be the file that is NOT attached');
        self::assertNotSame(1, $attached->getUid());

        $jobUid = 7;
        $this->attachToJob($jobUid, $attached->getUid());

        $path = $this->get(PdfFileResolver::class)
            ->resolve(['uid' => $jobUid, 'source_type' => 'pdf_fal', 'source_pdf' => 1]);

        self::assertSame($attachedBytes, (string) file_get_contents($path));
    }

    public function testThrowsForFalSourceWithoutFile(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job')
            ->insert('tx_nrrepurpose_domain_model_job', ['uid' => 4, 'pid' => 0, 'source_type' => 'pdf_fal', 'source_pdf' => 0]);

        $resolver = $this->get(PdfFileResolver::class);
        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379441);
        $resolver->resolve(['uid' => 4, 'source_type' => 'pdf_fal', 'source_pdf' => 0]);
    }

    /** A pdf_fal job with one attached file, stored as DataHandler stores it. */
    private function attachToJob(int $jobUid, int $fileUid): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('tx_nrrepurpose_domain_model_job')->insert('tx_nrrepurpose_domain_model_job', [
            'uid' => $jobUid, 'pid' => 0, 'source_type' => 'pdf_fal', 'source_pdf' => 1,
        ]);
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'pid'         => 0,
            'uid_local'   => $fileUid,
            'uid_foreign' => $jobUid,
            'tablenames'  => 'tx_nrrepurpose_domain_model_job',
            'fieldname'   => 'source_pdf',
        ]);
    }
}
