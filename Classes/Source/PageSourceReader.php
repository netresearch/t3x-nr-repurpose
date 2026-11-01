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
use Netresearch\NrRepurpose\Utility\ScalarCast;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Turns a page source into a document, in the worker. Each selected page is fetched through its
 * public URL by the same WebPageFetcher a url job uses, so the text is what a visitor reads,
 * plugin output included, and nothing a visitor may not read reaches the language model (ADR-010).
 *
 * The owner's access is checked again here: the form checked it when the job was made, and
 * permissions, mounts and the tree can change until the worker gets to it.
 *
 * A page that cannot be fetched is counted and the next one is read; the job fails only when no
 * page gave any text.
 */
class PageSourceReader
{
    public function __construct(
        private readonly PageTreeWalker $walker,
        private readonly SourceAccess $sourceAccess,
        private readonly PageSourceLimits $limits,
        private readonly WebPageFetcher $fetcher,
        private readonly PageUrlResolver $urls,
        private readonly LoggerInterface $logger,
    ) {}

    public function read(JobSnapshot $job): SourceDocument
    {
        $owner = $this->sourceAccess->owner($job->beUser);
        if (!$owner instanceof BackendUserAuthentication) {
            throw new IngestionException('A page source needs a job owner with an enabled backend user', 1749379480);
        }

        $root      = $this->rootPage($job, $owner);
        $selection = $this->walker->select($root, $job->sourceDepth, $owner, $this->limits->maxPages());
        if ($selection->pages === []) {
            throw new IngestionException(sprintf('Page %s and its subpages hold no page a visitor can open', $job->sourceValue), 1749379483);
        }

        $collected = $this->collect($job, $selection);
        $first     = $collected->first();
        if (!$first instanceof SourceDocument) {
            throw new IngestionException(sprintf('None of the %d selected pages could be read: %s', $collected->failed, $collected->firstFailure), 1749379484);
        }

        return new SourceDocument(
            title: $collected->count() === 1 ? $first->title : ScalarCast::string($root['title'] ?? ''),
            text: $collected->text(),
            sourceLabel: $first->sourceLabel,
            pageCount: 0,
            languageHint: $first->languageHint,
            meta: [
                'fetchedVia'   => 'static',
                'sourceReport' => new SourceReport(
                    pagesUsed: $collected->count(),
                    pagesFailed: $collected->failed,
                    skippedHidden: $selection->skippedHidden,
                    skippedRestricted: $selection->skippedRestricted,
                    skippedExcluded: $selection->skippedExcluded,
                    skippedNoAccess: $selection->skippedNoAccess,
                    pagesLeftOut: $collected->leftOut,
                    cutByPageLimit: $selection->cutByPageLimit,
                    cutByTextLimit: $collected->cutByText,
                    maxPages: $this->limits->maxPages(),
                    maxChars: $this->limits->maxChars(),
                ),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function rootPage(JobSnapshot $job, BackendUserAuthentication $owner): array
    {
        $pageUid = ctype_digit($job->sourceValue) ? (int) $job->sourceValue : 0;
        $root    = $this->walker->findPage($pageUid);
        if ($root === null) {
            throw new IngestionException(sprintf('Page %d no longer exists', $pageUid), 1749379481);
        }

        if (!$this->sourceAccess->mayReadPage($owner, $root)) {
            throw new IngestionException(sprintf('The job owner may not read page %d', $pageUid), 1749379482);
        }

        return $root;
    }

    private function collect(JobSnapshot $job, PageSelection $selection): CollectedPages
    {
        $collected = new CollectedPages($this->limits->maxChars());
        $origin    = $this->urls->originOf($job->sourceUrl);
        foreach ($selection->pages as $page) {
            if ($collected->cutByText) {
                ++$collected->leftOut;

                continue;
            }

            $uid = ScalarCast::int($page['uid'] ?? 0);
            try {
                $collected->add($this->fetch($uid, $origin), ScalarCast::string($page['title'] ?? ''));
            } catch (IngestionException $e) {
                $this->logger->warning('Page source: reading a page failed', ['job' => $job->uid, 'page' => $uid, 'exception' => $e]);
                $collected->fail($e->getMessage());
            }
        }

        return $collected;
    }

    private function fetch(int $pageUid, string $origin): SourceDocument
    {
        $url = $this->urls->resolve($pageUid, $origin);

        return $url->hostFromSite ? $this->fetcher->fetchOwnPage($url->url) : $this->fetcher->fetch($url->url);
    }
}
