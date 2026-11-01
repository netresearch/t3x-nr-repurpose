<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Source;

use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Source\JobSourceAssigner;
use Netresearch\NrRepurpose\Source\SourceRefusedException;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;

/** What the server makes of the form's picks, whatever the form sent. */
#[CoversClass(JobSourceAssigner::class)]
final class JobSourceAssignerTest extends AbstractFunctionalTestCase
{
    private const int ADMIN = 1;

    private const int EDITOR = 2;

    private const string ORIGIN = 'https://backend.example.org';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SourcePages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SourceUsers.csv');
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://www.example.org/');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function aUrlSourceIsLeftAlone(): void
    {
        $job = $this->job('url', 'https://example.com/');

        $source = $this->assigner()->assign($job, $this->user(self::ADMIN), 10, 2, 7, self::ORIGIN);

        self::assertSame('https://example.com/', $job->getSourceValue());
        self::assertSame(0, $job->getSourceDepth());
        self::assertSame(0, $source->fileUid);
    }

    #[Test]
    public function aPagePickGivesTheJobThePageItsDepthAndItsLabel(): void
    {
        // The text field of the hidden URL block still posts its value; it must not survive.
        $job = $this->job('page', 'https://leftover.example/');

        $this->assigner()->assign($job, $this->user(self::EDITOR), 10, 2, 0, self::ORIGIN);

        self::assertSame('10', $job->getSourceValue());
        self::assertSame(2, $job->getSourceDepth());
        self::assertSame('About [10]', $job->getSourceLabel());
        self::assertSame('https://www.example.org/about', $job->getSourceUrl());
    }

    /** A site with `base: /` answers on any host; only the request knows which one. */
    #[Test]
    public function aSiteWithoutAHostTakesTheHostOfTheRequest(): void
    {
        $this->get(SiteWriter::class)->write('main', ['rootPageId' => 1, 'base' => '/', 'languages' => [['title' => 'English', 'enabled' => true, 'languageId' => 0, 'base' => '/', 'locale' => 'en_US.UTF-8', 'navigationTitle' => 'English', 'flag' => 'us']]]);
        $this->get(SiteFinder::class)->getAllSites(false);
        $job = $this->job('page');

        $this->assigner()->assign($job, $this->user(self::ADMIN), 10, 0, 0, self::ORIGIN);

        self::assertSame(self::ORIGIN . '/about', $job->getSourceUrl());
    }

    /** @return array<string, array{int, int}> */
    public static function tamperedDepths(): array
    {
        return ['below zero' => [-5, 0], 'above all levels' => [100000, 99]];
    }

    #[Test]
    #[DataProvider('tamperedDepths')]
    public function aTamperedDepthIsClamped(int $depth, int $expected): void
    {
        $job = $this->job('page');

        $this->assigner()->assign($job, $this->user(self::ADMIN), 10, $depth, 0, self::ORIGIN);

        self::assertSame($expected, $job->getSourceDepth());
    }

    /** @return array<string, array{int, int, int, string}> */
    public static function refusedPages(): array
    {
        return [
            'nothing picked'       => [self::ADMIN, 0, 0, JobSourceAssigner::REFUSED_NO_PAGE],
            'no such page'         => [self::ADMIN, 999, 0, JobSourceAssigner::REFUSED_PAGE_ACCESS],
            'a translation record' => [self::ADMIN, 200, 0, JobSourceAssigner::REFUSED_PAGE_ACCESS],
            'outside the mount'    => [self::EDITOR, 2, 0, JobSourceAssigner::REFUSED_PAGE_ACCESS],
            'no show permission'   => [self::EDITOR, 101, 0, JobSourceAssigner::REFUSED_PAGE_ACCESS],
            'outside any site'     => [self::ADMIN, 2, 0, JobSourceAssigner::REFUSED_PAGE_URL],
            'hidden, page alone'   => [self::ADMIN, 11, 0, JobSourceAssigner::REFUSED_PAGE_EMPTY],
            'folder, page alone'   => [self::ADMIN, 14, 0, JobSourceAssigner::REFUSED_PAGE_EMPTY],
        ];
    }

    #[Test]
    #[DataProvider('refusedPages')]
    public function aPageIsRefused(int $user, int $page, int $depth, string $reason): void
    {
        $this->assertRefused($reason, $this->job('page'), $user, $page, $depth, 0);
    }

    #[Test]
    public function aFolderWithSubpagesInTheDepthIsAccepted(): void
    {
        $job = $this->job('page');

        $this->assigner()->assign($job, $this->user(self::ADMIN), 14, 1, 0, self::ORIGIN);

        self::assertSame('14', $job->getSourceValue());
    }

    #[Test]
    public function aTreeOverThePageLimitIsAcceptedAndReported(): void
    {
        $this->get(ExtensionConfiguration::class)->set('nr_repurpose', ['maxSourcePages' => 1]);

        $source = $this->assigner()->assign($this->job('page'), $this->user(self::ADMIN), 10, 2, 0, self::ORIGIN);

        self::assertTrue($source->cutByPageLimit);
    }

    #[Test]
    public function aFilePickGivesTheJobTheFileAndItsLabel(): void
    {
        $file = $this->file('allowed', 'report.pdf');
        $job  = $this->job('pdf_fal', 'https://leftover.example/');

        $source = $this->assigner()->assign($job, $this->user(self::EDITOR), 0, 0, $file->getUid(), self::ORIGIN);

        self::assertSame($file->getUid(), $source->fileUid);
        self::assertSame('', $job->getSourceValue());
        self::assertSame(sprintf('report.pdf [%d]', $file->getUid()), $job->getSourceLabel());
    }

    #[Test]
    public function nothingPickedIsRefusedForAFileSource(): void
    {
        $this->assertRefused(JobSourceAssigner::REFUSED_NO_FILE, $this->job('pdf_fal'), self::ADMIN, 0, 0, 0);
    }

    #[Test]
    public function aFileThatDoesNotExistIsRefusedLikeAForbiddenOne(): void
    {
        $this->assertRefused(JobSourceAssigner::REFUSED_FILE_ACCESS, $this->job('pdf_fal'), self::ADMIN, 0, 0, 999);
    }

    #[Test]
    public function aFileOutsideTheMountsIsRefused(): void
    {
        $file = $this->file('other', 'report.pdf');

        $this->assertRefused(JobSourceAssigner::REFUSED_FILE_ACCESS, $this->job('pdf_fal'), self::EDITOR, 0, 0, $file->getUid());
    }

    #[Test]
    public function aFileOtherThanAPdfIsRefused(): void
    {
        $file = $this->file('allowed', 'notes.txt');

        $this->assertRefused(JobSourceAssigner::REFUSED_FILE_TYPE, $this->job('pdf_fal'), self::ADMIN, 0, 0, $file->getUid());
    }

    #[Test]
    public function noBackendUserGetsNoPage(): void
    {
        $this->expectException(SourceRefusedException::class);
        $this->expectExceptionMessage(JobSourceAssigner::REFUSED_PAGE_ACCESS);

        $this->assigner()->assign($this->job('page'), null, 10, 0, 0, self::ORIGIN);
    }

    #[Test]
    public function aSourceTypeTheFormDoesNotOfferIsRefused(): void
    {
        $this->assertRefused(JobSourceAssigner::REFUSED_TYPE, $this->job('ftp'), self::ADMIN, 0, 0, 0);
    }

    private function assertRefused(string $reason, Job $job, int $user, int $page, int $depth, int $file): void
    {
        try {
            $this->assigner()->assign($job, $this->user($user), $page, $depth, $file, self::ORIGIN);
            self::fail('The source must be refused: ' . $reason);
        } catch (SourceRefusedException $e) {
            self::assertSame($reason, $e->getMessage());
        }

        self::assertSame('', $job->getSourceLabel(), 'A refused source leaves nothing on the job');
    }

    private function assigner(): JobSourceAssigner
    {
        return $this->get(JobSourceAssigner::class);
    }

    private function job(string $type, string $value = ''): Job
    {
        $job = new Job();
        $job->setSourceType($type);
        $job->setSourceValue($value);

        return $job;
    }

    private function user(int $uid): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser($uid);
        self::assertInstanceOf(BackendUserAuthentication::class, $user);

        return $user;
    }

    private function file(string $folderName, string $name): File
    {
        $storage = $this->get(StorageRepository::class)->getDefaultStorage();
        self::assertNotNull($storage);
        $previous = $storage->getEvaluatePermissions();
        $storage->setEvaluatePermissions(false);
        $root   = $storage->getRootLevelFolder();
        $folder = $root->hasFolder($folderName) ? $root->getSubfolder($folderName) : $root->createFolder($folderName);
        $file   = $storage->createFile($name, $folder);
        $storage->setEvaluatePermissions($previous);
        self::assertInstanceOf(File::class, $file);

        return $file;
    }
}
