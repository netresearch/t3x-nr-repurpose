<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Source;

use Netresearch\NrRepurpose\Domain\ValueObject\JobSnapshot;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceReport;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\WebPageFetcher;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Routing\InvalidRouteArgumentsException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Turns a page source into a document, in the worker. Each selected page is fetched through its
 * public URL by the same WebPageFetcher a url job uses, so the text is what a visitor reads,
 * plugin output included, and nothing a visitor may not read reaches the language model (ADR-010).
 *
 * The owner's access is checked again here: the form checked it when the job was made, and
 * permissions, mounts and the tree can change until the worker gets to it.
 *
 * A page that cannot be fetched is counted and the next one is read; the job fails only when no
 * page gave any text. The text limit ends the selection at a page boundary, except for a first
 * page longer than the limit, which is cut.
 */
class PageSourceReader
{
    public function __construct(
        private readonly PageTreeWalker $walker,
        private readonly SourceAccess $sourceAccess,
        private readonly PageSourceLimits $limits,
        private readonly WebPageFetcher $fetcher,
        private readonly SiteFinder $siteFinder,
        private readonly LoggerInterface $logger,
    ) {}

    public function read(JobSnapshot $job): SourceDocument
    {
        $owner = $this->sourceAccess->owner($job->beUser);
        if (!$owner instanceof BackendUserAuthentication) {
            throw new IngestionException('A page source needs a job owner with an enabled backend user', 1749379480);
        }

        $pageUid = ctype_digit($job->sourceValue) ? (int) $job->sourceValue : 0;
        $root    = $this->walker->findPage($pageUid);
        if ($root === null) {
            throw new IngestionException(sprintf('Page %d no longer exists', $pageUid), 1749379481);
        }

        if (!$this->sourceAccess->mayReadPage($owner, $root)) {
            throw new IngestionException(sprintf('The job owner may not read page %d', $pageUid), 1749379482);
        }

        $maxChars  = $this->limits->maxChars();
        $selection = $this->walker->select($root, $job->sourceDepth, $owner, $this->limits->maxPages());
        if ($selection->pages === []) {
            throw new IngestionException(sprintf('Page %d and its subpages hold no page a visitor can open', $pageUid), 1749379483);
        }

        $documents    = [];
        $sections     = [];
        $length       = 0;
        $failed       = 0;
        $leftOut      = 0;
        $cutByText    = false;
        $firstFailure = '';
        foreach ($selection->pages as $page) {
            if ($cutByText) {
                ++$leftOut;

                continue;
            }

            $uid = is_numeric($page['uid'] ?? null) ? (int) $page['uid'] : 0;
            try {
                $document = $this->fetcher->fetchOwnPage($this->pageUrl($uid));
            } catch (IngestionException $e) {
                $this->logger->warning('Page source: reading a page failed', ['job' => $job->uid, 'page' => $uid, 'exception' => $e]);
                $firstFailure = $firstFailure === '' ? $e->getMessage() : $firstFailure;
                ++$failed;

                continue;
            }

            $text = $document->text;
            if (mb_strlen($text) > $maxChars - $length) {
                $cutByText = true;
                if ($sections !== []) {
                    ++$leftOut;

                    continue;
                }

                $text = mb_substr($text, 0, $maxChars);
            }

            $length += mb_strlen($text);
            $title       = is_string($page['title'] ?? null) ? $page['title'] : '';
            $documents[] = $document;
            $sections[]  = ['title' => $title, 'text' => $text];
        }

        if ($sections === []) {
            throw new IngestionException(sprintf('None of the %d selected pages could be read: %s', $failed, $firstFailure), 1749379484);
        }

        $first  = $documents[0];
        $single = count($sections) === 1;

        return new SourceDocument(
            title: $single ? $first->title : (is_string($root['title'] ?? null) ? $root['title'] : ''),
            text: $single ? $sections[0]['text'] : implode("\n\n", array_map(
                static fn (array $section): string => '# ' . $section['title'] . "\n\n" . $section['text'],
                $sections,
            )),
            sourceLabel: $first->sourceLabel,
            pageCount: 0,
            languageHint: $first->languageHint,
            meta: [
                'fetchedVia'   => 'static',
                'sourceReport' => new SourceReport(
                    pagesUsed: count($sections),
                    pagesFailed: $failed,
                    skippedHidden: $selection->skippedHidden,
                    skippedRestricted: $selection->skippedRestricted,
                    skippedExcluded: $selection->skippedExcluded,
                    skippedNoAccess: $selection->skippedNoAccess,
                    pagesLeftOut: $leftOut,
                    cutByPageLimit: $selection->cutByPageLimit,
                    cutByTextLimit: $cutByText,
                    maxPages: $this->limits->maxPages(),
                    maxChars: $maxChars,
                ),
            ],
        );
    }

    /** @throws IngestionException for a page outside any site, which has no public URL */
    private function pageUrl(int $uid): string
    {
        try {
            return (string) $this->siteFinder->getSiteByPageId($uid)->getRouter()->generateUri($uid, ['_language' => 0]);
        } catch (SiteNotFoundException|InvalidRouteArgumentsException $e) {
            throw new IngestionException(sprintf('Page %d has no public URL: it belongs to no site', $uid), 1749379485, $e);
        }
    }
}
