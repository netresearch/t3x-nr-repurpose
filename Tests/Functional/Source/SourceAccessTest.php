<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Source;

use Netresearch\NrRepurpose\Source\PageTreeWalker;
use Netresearch\NrRepurpose\Source\SourceAccess;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\StorageRepository;

/** Page and file access of a user who is not the logged-in one: the worker's position. */
#[CoversClass(SourceAccess::class)]
final class SourceAccessTest extends AbstractFunctionalTestCase
{
    private const int ADMIN = 1;

    private const int EDITOR = 2;

    private const int NO_READ_PERMISSION = 3;

    private const int DISABLED = 4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SourcePages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SourceUsers.csv');
    }

    #[Test]
    public function ownerIsNullForNoUserAndForADisabledOne(): void
    {
        $access = $this->get(SourceAccess::class);

        self::assertNull($access->owner(0));
        self::assertNull($access->owner(self::DISABLED));
        self::assertNull($access->owner(999));
        self::assertInstanceOf(BackendUserAuthentication::class, $access->owner(self::EDITOR));
    }

    /** @return array<string, array{int, int, bool}> */
    public static function pages(): array
    {
        return [
            'editor, own mount'            => [self::EDITOR, 10, true],
            'editor, below the mount'      => [self::EDITOR, 100, true],
            'editor, above the mount'      => [self::EDITOR, 1, false],
            'editor, outside the mount'    => [self::EDITOR, 2, false],
            'editor, no show permission'   => [self::EDITOR, 101, false],
            'administrator, outside mount' => [self::ADMIN, 2, true],
        ];
    }

    #[Test]
    #[DataProvider('pages')]
    public function mayReadPageNeedsTheMountAndTheShowPermission(int $user, int $page, bool $expected): void
    {
        $row = $this->get(PageTreeWalker::class)->findPage($page);
        self::assertIsArray($row);

        self::assertSame($expected, $this->get(SourceAccess::class)->mayReadPage($this->owner($user), $row));
    }

    /** @return array<string, array{int, string, bool}> */
    public static function files(): array
    {
        return [
            'editor, in the mount'                => [self::EDITOR, 'allowed/report.pdf', true],
            'editor, in a folder of the mount'    => [self::EDITOR, 'allowed/sub/report.pdf', true],
            'editor, outside the mount'           => [self::EDITOR, 'other/report.pdf', false],
            'mounted without the read permission' => [self::NO_READ_PERMISSION, 'allowed/report.pdf', false],
            'administrator, outside any mount'    => [self::ADMIN, 'other/report.pdf', true],
        ];
    }

    #[Test]
    #[DataProvider('files')]
    public function mayReadFileNeedsTheMountAndTheReadPermission(int $user, string $path, bool $expected): void
    {
        $file = $this->file($path);

        self::assertSame($expected, $this->get(SourceAccess::class)->mayReadFile($this->owner($user), $file));
    }

    /**
     * ResourceStorage objects are shared within the worker process. A check that configured the
     * storage for one owner would leave that owner's mounts for the next job.
     */
    #[Test]
    public function mayReadFileLeavesTheSharedStorageAsItWas(): void
    {
        $file    = $this->file('allowed/report.pdf');
        $storage = $file->getStorage();
        $mounts  = $storage->getFileMounts();
        $flag    = $storage->getEvaluatePermissions();

        $this->get(SourceAccess::class)->mayReadFile($this->owner(self::EDITOR), $file);

        self::assertSame($mounts, $storage->getFileMounts());
        self::assertSame($flag, $storage->getEvaluatePermissions());
    }

    private function owner(int $uid): BackendUserAuthentication
    {
        $user = $this->get(SourceAccess::class)->owner($uid);
        self::assertInstanceOf(BackendUserAuthentication::class, $user);

        return $user;
    }

    private function file(string $path): File
    {
        $storage = $this->get(StorageRepository::class)->getDefaultStorage();
        self::assertNotNull($storage);
        $folder = $storage->getRootLevelFolder();
        $parts  = explode('/', $path);
        $name   = array_pop($parts);
        foreach ($parts as $part) {
            $folder = $folder->hasFolder($part) ? $folder->getSubfolder($part) : $folder->createFolder($part);
        }

        $file = $storage->createFile($name, $folder);
        self::assertInstanceOf(File::class, $file);

        return $file;
    }
}
