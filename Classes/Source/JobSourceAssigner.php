<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Source;

use Netresearch\NrRepurpose\Domain\Enum\SourceType;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Applies what the new-job form picked to the job. Nothing the form sends is trusted: the page
 * and the file are read here, against the requesting user's access, and the job gets its source
 * value and label from those records.
 */
final readonly class JobSourceAssigner
{
    public const string REFUSED_NO_PAGE = 'source.refused.page.none';

    public const string REFUSED_PAGE_ACCESS = 'source.refused.page.access';

    public const string REFUSED_PAGE_EMPTY = 'source.refused.page.empty';

    public const string REFUSED_PAGE_URL = 'source.refused.page.url';

    public const string REFUSED_NO_FILE = 'source.refused.file.none';

    public const string REFUSED_FILE_ACCESS = 'source.refused.file.access';

    public const string REFUSED_FILE_TYPE = 'source.refused.file.type';

    public const string REFUSED_TYPE = 'source.refused.type';

    private const string PDF_EXTENSION = 'pdf';

    /** Leaves the id room in the 255 characters of source_label. */
    private const int MAX_LABEL_TITLE = 240;

    /** "All levels" in the form; the page limit bounds the walk. */
    public const int MAX_DEPTH = 99;

    public function __construct(
        private PageTreeWalker $walker,
        private SourceAccess $sourceAccess,
        private PageSourceLimits $limits,
        private ResourceFactory $resourceFactory,
        private PageUrlResolver $urls,
    ) {}

    /**
     * @param string $origin scheme and host of the request, for a site whose base names no host
     *
     * @throws SourceRefusedException with the label key of the reason
     */
    public function assign(Job $job, ?BackendUserAuthentication $user, int $pageUid, int $depth, int $fileUid, string $origin): AssignedSource
    {
        return match (SourceType::tryFrom($job->getSourceType())) {
            SourceType::Url, SourceType::PdfUrl => new AssignedSource(),
            SourceType::Page                    => $this->assignPage($job, $user, $pageUid, $depth, $origin),
            SourceType::PdfFal                  => $this->assignFile($job, $user, $fileUid),
            null                                => throw new SourceRefusedException(self::REFUSED_TYPE, 1790600001),
        };
    }

    /** "Title [uid]", as the form shows a picked item and the module shows the job's source. */
    public static function label(string $title, int $uid): string
    {
        return sprintf('%s [%d]', mb_substr($title, 0, self::MAX_LABEL_TITLE), $uid);
    }

    private function assignPage(Job $job, ?BackendUserAuthentication $user, int $pageUid, int $depth, string $origin): AssignedSource
    {
        if ($pageUid <= 0) {
            throw new SourceRefusedException(self::REFUSED_NO_PAGE, 1790600002);
        }

        $page = $this->walker->findPage($pageUid);
        if ($page === null || !$user instanceof BackendUserAuthentication || !$this->sourceAccess->mayReadPage($user, $page)) {
            throw new SourceRefusedException(self::REFUSED_PAGE_ACCESS, 1790600003);
        }

        $depth     = min(max(0, $depth), self::MAX_DEPTH);
        $selection = $this->walker->select($page, $depth, $user, $this->limits->maxPages());
        if ($selection->pages === []) {
            throw new SourceRefusedException(self::REFUSED_PAGE_EMPTY, 1790600004);
        }

        // The first page the job reads: the picked page may be a folder or hidden, and have no URL
        // a visitor could open.
        try {
            $url = $this->urls->resolve($selection->pages[0], $origin);
        } catch (IngestionException) {
            throw new SourceRefusedException(self::REFUSED_PAGE_URL, 1790600009);
        }

        $title = $page['title'] ?? '';
        $job->setSourceValue((string) $pageUid);
        $job->setSourceUrl($url->url);
        // The site of the page whose URL the job keeps: its LLM configuration runs the job (ADR-012).
        $job->setSite($url->site);
        $job->setSourceDepth($depth);
        $job->setSourceLabel(self::label(is_string($title) ? $title : '', $pageUid));

        return new AssignedSource(cutByPageLimit: $selection->cutByPageLimit);
    }

    private function assignFile(Job $job, ?BackendUserAuthentication $user, int $fileUid): AssignedSource
    {
        if ($fileUid <= 0) {
            throw new SourceRefusedException(self::REFUSED_NO_FILE, 1790600005);
        }

        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (Throwable) {
            // Same answer as for a file the user may not read: the uid tells nothing about others' files.
            throw new SourceRefusedException(self::REFUSED_FILE_ACCESS, 1790600006);
        }

        // checkActionPermission() answers for the requesting user: StoragePermissionsAspect set the
        // storage up for them. The worker cannot use it, see SourceAccess.
        if (!$user instanceof BackendUserAuthentication
            || !$file->checkActionPermission('read')
            || !$this->sourceAccess->mayReadFile($user, $file)
        ) {
            throw new SourceRefusedException(self::REFUSED_FILE_ACCESS, 1790600007);
        }

        if (strtolower($file->getExtension()) !== self::PDF_EXTENSION) {
            throw new SourceRefusedException(self::REFUSED_FILE_TYPE, 1790600008);
        }

        $job->setSourceValue('');
        $job->setSourceLabel(self::label($file->getName(), $fileUid));

        return new AssignedSource(fileUid: $fileUid);
    }
}
