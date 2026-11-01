<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Source;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Selects the pages of a page source: the picked page and its subpages down to the chosen depth,
 * level by level, in the order of the page tree, so a limit keeps the pages nearest the picked one.
 *
 * Pages are read the way an anonymous visitor reaches them (ADR-010), so a page a visitor cannot
 * open is not selected. Reading the default language of the live workspace:
 *
 * - no access for the user (outside their web mounts, or no "show page" permission): left out
 *   with its subpages, which the user cannot reach in the page tree either;
 * - hidden, before its start time, after its end time: left out, its subpages stay selected, as
 *   they stay online; with "extend to subpages" the subpages go too, as they do in the frontend;
 * - restricted to frontend groups: the same;
 * - no content of its own (link, shortcut, backend user section, mount point, spacer, folder, any
 *   doktype from 200 on): left out, its subpages stay selected.
 */
class PageTreeWalker
{
    private const string TABLE = 'pages';

    /**
     * Pages looked at in one walk. Only a tree of mostly unusable pages comes near it, as the walk
     * stops at the page limit; it keeps such a tree from reading every row.
     */
    private const int MAX_VISITED_PAGES = 2000;

    /** Doktypes below 200 without content of their own. From 200 on a doktype is a storage page. */
    private const array DOKTYPES_WITHOUT_CONTENT = [
        PageRepository::DOKTYPE_LINK,
        PageRepository::DOKTYPE_SHORTCUT,
        PageRepository::DOKTYPE_BE_USER_SECTION,
        PageRepository::DOKTYPE_MOUNTPOINT,
        PageRepository::DOKTYPE_SPACER,
    ];

    private const int FIRST_STORAGE_DOKTYPE = 200;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SourceAccess $sourceAccess,
    ) {}

    /**
     * The page in the default language and the live workspace, or null: a deleted page, a
     * translation record or a workspace version is no page source.
     *
     * @return array<string, mixed>|null
     */
    public function findPage(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        $queryBuilder = $this->queryBuilder();
        $row          = $queryBuilder->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $root the row findPage() returned */
    public function select(array $root, int $depth, BackendUserAuthentication $user, int $maxPages): PageSelection
    {
        $pages   = [];
        $skipped = [];
        $visited = 0;
        $level   = [$root];

        for ($distance = 0; $level !== []; ++$distance) {
            $parents = [];
            foreach ($level as $page) {
                ++$visited;
                $verdict = $this->verdict($page, $user);
                if ($verdict === PageVerdict::Usable && count($pages) >= $maxPages) {
                    return $this->selection($pages, $skipped, true);
                }

                if ($verdict === PageVerdict::Usable) {
                    $pages[] = $page;
                } else {
                    $skipped[$verdict->name] = ($skipped[$verdict->name] ?? 0) + 1;
                }

                if ($this->subpagesStaySelected($page, $verdict)) {
                    $parents[] = $this->intColumn($page, 'uid');
                }
            }

            if ($distance >= $depth || $parents === []) {
                break;
            }

            if ($visited >= self::MAX_VISITED_PAGES) {
                return $this->selection($pages, $skipped, true);
            }

            $level = $this->childrenOf($parents);
        }

        return $this->selection($pages, $skipped, false);
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @param array<string, int>         $skipped verdict name => pages
     */
    private function selection(array $pages, array $skipped, bool $cut): PageSelection
    {
        return new PageSelection(
            pages: $pages,
            skippedHidden: $skipped[PageVerdict::Hidden->name] ?? 0,
            skippedRestricted: $skipped[PageVerdict::Restricted->name] ?? 0,
            skippedExcluded: $skipped[PageVerdict::Excluded->name] ?? 0,
            skippedNoAccess: $skipped[PageVerdict::NoAccess->name] ?? 0,
            cutByPageLimit: $cut,
        );
    }

    /** @param array<string, mixed> $page */
    public function verdict(array $page, BackendUserAuthentication $user): PageVerdict
    {
        return match (true) {
            !$this->sourceAccess->mayReadPage($user, $page) => PageVerdict::NoAccess,
            $this->isOffline($page)                         => PageVerdict::Hidden,
            !$this->isPublic($page)                         => PageVerdict::Restricted,
            !$this->hasContent($page)                       => PageVerdict::Excluded,
            default                                         => PageVerdict::Usable,
        };
    }

    /** @param array<string, mixed> $page */
    private function subpagesStaySelected(array $page, PageVerdict $verdict): bool
    {
        return match ($verdict) {
            PageVerdict::NoAccess                        => false,
            PageVerdict::Hidden, PageVerdict::Restricted => $this->intColumn($page, 'extendToSubpages') === 0,
            PageVerdict::Excluded, PageVerdict::Usable   => true,
        };
    }

    /** @param array<string, mixed> $page */
    private function isOffline(array $page): bool
    {
        $now   = time();
        $start = $this->intColumn($page, 'starttime');
        $end   = $this->intColumn($page, 'endtime');

        return $this->intColumn($page, 'hidden') !== 0
            || $start > $now
            || ($end !== 0 && $end <= $now);
    }

    /**
     * Whether an anonymous visitor may open the page: no group, or a list holding "hide at login"
     * (-1). "Show at any login" (-2) and real groups need a frontend login.
     *
     * @param array<string, mixed> $page
     */
    private function isPublic(array $page): bool
    {
        $groups = $page['fe_group'] ?? '';
        $groups = is_scalar($groups) ? GeneralUtility::intExplode(',', (string) $groups, true) : [];

        return $groups === [] || in_array(0, $groups, true) || in_array(-1, $groups, true);
    }

    /** @param array<string, mixed> $page */
    private function hasContent(array $page): bool
    {
        $doktype = $this->intColumn($page, 'doktype');

        return $doktype < self::FIRST_STORAGE_DOKTYPE && !in_array($doktype, self::DOKTYPES_WITHOUT_CONTENT, true);
    }

    /**
     * The children in the order of their parents, which is the tree's, and by sorting within one.
     *
     * @param non-empty-list<int> $parents
     *
     * @return list<array<string, mixed>>
     */
    private function childrenOf(array $parents): array
    {
        $queryBuilder = $this->queryBuilder();

        $children = $queryBuilder->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($parents, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting')
            ->addOrderBy('uid')
            ->setMaxResults(self::MAX_VISITED_PAGES)
            ->executeQuery()
            ->fetchAllAssociative();

        $position = array_flip($parents);
        usort(
            $children,
            fn (array $a, array $b): int => ($position[$this->intColumn($a, 'pid')] ?? 0) <=> ($position[$this->intColumn($b, 'pid')] ?? 0),
        );

        return $children;
    }

    private function queryBuilder(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder;
    }

    /** @param array<string, mixed> $page */
    private function intColumn(array $page, string $column): int
    {
        $value = $page[$column] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
