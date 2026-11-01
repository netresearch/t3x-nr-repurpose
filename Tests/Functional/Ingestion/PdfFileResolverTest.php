<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Ingestion;

use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\JobSnapshots;
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
        $path     = $resolver->resolve(JobSnapshots::of(['uid' => 3, 'source_type' => 'pdf_fal', 'source_pdf' => 1]));

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
            ->resolve(JobSnapshots::of(['uid' => $jobUid, 'source_type' => 'pdf_fal', 'source_pdf' => 1]));

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
        $resolver->resolve(JobSnapshots::of(['uid' => 4, 'source_type' => 'pdf_fal', 'source_pdf' => 0]));
    }

    /** The file the form accepted may have moved out of the owner's mounts since. */
    public function testRefusesAFileTheJobOwnerMayNotRead(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Source/Fixtures/SourceUsers.csv');
        $file = $this->createFile('other', 'outside.pdf');
        $this->attachToJob(5, $file);

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379449);

        $this->get(PdfFileResolver::class)->resolve(JobSnapshots::of(['uid' => 5, 'source_type' => 'pdf_fal', 'be_user' => 2]));
    }

    public function testRefusesTheFileOfAJobOwnerWhoIsNoLongerEnabled(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Source/Fixtures/SourceUsers.csv');
        $file = $this->createFile('allowed', 'report.pdf');
        $this->attachToJob(6, $file);

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379449);

        $this->get(PdfFileResolver::class)->resolve(JobSnapshots::of(['uid' => 6, 'source_type' => 'pdf_fal', 'be_user' => 4]));
    }

    public function testResolvesAFileInTheJobOwnersMount(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Source/Fixtures/SourceUsers.csv');
        $file = $this->createFile('allowed', 'report.pdf');
        $this->attachToJob(8, $file);

        $path = $this->get(PdfFileResolver::class)->resolve(JobSnapshots::of(['uid' => 8, 'source_type' => 'pdf_fal', 'be_user' => 2]));

        self::assertFileExists($path);
    }

    /** A job from the record view has no owner; it keeps running on the file it holds. */
    public function testAJobWithoutAnOwnerKeepsResolvingItsFile(): void
    {
        $file = $this->createFile('other', 'legacy.pdf');
        $this->attachToJob(9, $file);

        $path = $this->get(PdfFileResolver::class)->resolve(JobSnapshots::of(['uid' => 9, 'source_type' => 'pdf_fal', 'be_user' => 0]));

        self::assertFileExists($path);
    }

    /** The form's way of attaching: what it writes is what the worker's resolver reads. */
    public function testResolvesAFileAttachedByTheForm(): void
    {
        $file = $this->createFile('allowed', 'picked.pdf');
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job')
            ->insert('tx_nrrepurpose_domain_model_job', ['uid' => 10, 'pid' => 101, 'source_type' => 'pdf_fal']);

        $this->get(JobProcessingRepository::class)->attachSourcePdf(10, $file);

        $path = $this->get(PdfFileResolver::class)->resolve(JobSnapshots::of(['uid' => 10, 'source_type' => 'pdf_fal']));
        self::assertStringEndsWith('/allowed/picked.pdf', $path);

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        self::assertSame(1, (int) $pool->getConnectionForTable('tx_nrrepurpose_domain_model_job')
            ->select(['source_pdf'], 'tx_nrrepurpose_domain_model_job', ['uid' => 10])->fetchOne());
        self::assertSame(101, (int) $pool->getConnectionForTable('sys_file_reference')
            ->select(['pid'], 'sys_file_reference', ['uid_foreign' => 10])->fetchOne());
        // The reference index is what the Filelist module's deletion warning reads.
        self::assertSame(1, $pool->getConnectionForTable('sys_refindex')->count('hash', 'sys_refindex', [
            'tablename' => 'sys_file_reference', 'ref_table' => 'sys_file', 'ref_uid' => $file,
        ]));
    }

    private function createFile(string $folderName, string $name): int
    {
        $storage = $this->get(StorageRepository::class)->getDefaultStorage();
        self::assertNotNull($storage);
        $folder = $storage->hasFolder($folderName) ? $storage->getFolder($folderName) : $storage->createFolder($folderName);
        $file   = $storage->createFile($name, $folder);
        $file->setContents('%PDF-1.4 test');

        return $file->getUid();
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
