<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Source;

use Netresearch\NrRepurpose\Source\PageSelection;
use Netresearch\NrRepurpose\Source\PageTreeWalker;
use Netresearch\NrRepurpose\Source\SourceAccess;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/** The rules of ADR-010, read back from a tree holding one page of each kind. */
#[CoversClass(PageTreeWalker::class)]
final class PageTreeWalkerTest extends AbstractFunctionalTestCase
{
    private const int ADMIN = 1;

    private const int EDITOR = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SourcePages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SourceUsers.csv');
    }

    #[Test]
    public function aDepthOfZeroSelectsThePageAlone(): void
    {
        self::assertSame([10], $this->uids($this->select(10, 0)));
    }

    /** @return array<string, array{int, list<int>}> */
    public static function depths(): array
    {
        return [
            'one level'  => [1, [10, 100]],
            'two levels' => [2, [10, 100, 1000]],
            'all levels' => [99, [10, 100, 1000]],
        ];
    }

    /** @param list<int> $expected */
    #[Test]
    #[DataProvider('depths')]
    public function theDepthCountsLevelsBelowThePickedPage(int $depth, array $expected): void
    {
        // Page 101 has no "show" permission for the editor, 1010 below it stays out with it.
        self::assertSame($expected, $this->uids($this->select(10, $depth, self::EDITOR)));
    }

    #[Test]
    public function pagesComeLevelByLevelInTreeOrder(): void
    {
        $selection = $this->select(1, 1);

        // Level one first, in sorting order; the translation of page 10 and page 18 (deleted) are no pages of the tree.
        self::assertSame([1, 10, 19], $this->uids($selection));
    }

    #[Test]
    public function aHiddenPageIsLeftOutAndItsSubpagesStay(): void
    {
        $selection = $this->select(11, 1);

        self::assertSame([110], $this->uids($selection));
        self::assertSame(1, $selection->skippedHidden);
    }

    #[Test]
    public function extendToSubpagesTakesTheSubpagesOutWithAHiddenPage(): void
    {
        $selection = $this->select(12, 1);

        self::assertSame([], $this->uids($selection));
        self::assertSame(1, $selection->skippedHidden);
    }

    /** @return array<string, array{int}> */
    public static function offlinePages(): array
    {
        return ['start time ahead' => [16], 'end time passed' => [17]];
    }

    #[Test]
    #[DataProvider('offlinePages')]
    public function aPageOutsideItsPublicationTimeCountsAsHidden(int $uid): void
    {
        $selection = $this->select($uid, 0);

        self::assertSame([], $this->uids($selection));
        self::assertSame(1, $selection->skippedHidden);
    }

    #[Test]
    public function aRestrictedPageIsLeftOutAndItsSubpagesStay(): void
    {
        $selection = $this->select(13, 1);

        self::assertSame([130], $this->uids($selection));
        self::assertSame(1, $selection->skippedRestricted);
    }

    #[Test]
    public function showAtAnyLoginIsRestrictedAndHideAtLoginIsNot(): void
    {
        self::assertSame(1, $this->select(20, 0)->skippedRestricted);
        self::assertSame([19], $this->uids($this->select(19, 0)));
    }

    #[Test]
    public function aFolderIsLeftOutAndItsSubpagesStay(): void
    {
        $selection = $this->select(14, 1);

        self::assertSame([140], $this->uids($selection));
        self::assertSame(1, $selection->skippedExcluded);
    }

    #[Test]
    public function aShortcutIsLeftOut(): void
    {
        self::assertSame(1, $this->select(15, 0)->skippedExcluded);
    }

    #[Test]
    public function aPageWithoutPermissionGoesWithItsSubpages(): void
    {
        $selection = $this->select(10, 2, self::EDITOR);

        self::assertNotContains(101, $this->uids($selection));
        self::assertNotContains(1010, $this->uids($selection));
        self::assertSame(1, $selection->skippedNoAccess);
    }

    #[Test]
    public function aPageLimitKeepsTheNearestPagesAndSaysSo(): void
    {
        $selection = $this->select(10, 2, self::ADMIN, 2);

        self::assertSame([10, 100], $this->uids($selection));
        self::assertTrue($selection->cutByPageLimit);
    }

    #[Test]
    public function aTreeExactlyAtTheLimitIsNotCut(): void
    {
        self::assertFalse($this->select(10, 2, self::EDITOR, 3)->cutByPageLimit);
    }

    #[Test]
    public function findPageFindsNeitherATranslationNorADeletedPage(): void
    {
        $walker = $this->get(PageTreeWalker::class);

        self::assertNull($walker->findPage(200));
        self::assertNull($walker->findPage(18));
        self::assertNull($walker->findPage(0));
        self::assertSame('About', $walker->findPage(10)['title'] ?? null);
    }

    private function select(int $uid, int $depth, int $user = self::ADMIN, int $maxPages = 25): PageSelection
    {
        $walker = $this->get(PageTreeWalker::class);
        $page   = $walker->findPage($uid);
        self::assertIsArray($page);

        return $walker->select($page, $depth, $this->user($user), $maxPages);
    }

    private function user(int $uid): BackendUserAuthentication
    {
        $user = $this->get(SourceAccess::class)->owner($uid);
        self::assertInstanceOf(BackendUserAuthentication::class, $user);

        return $user;
    }

    /** @return list<int> */
    private function uids(PageSelection $selection): array
    {
        return array_map(static fn (array $page): int => (int) ($page['uid'] ?? 0), $selection->pages);
    }
}
