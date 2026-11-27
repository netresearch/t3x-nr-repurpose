<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\SourceReview;

use Netresearch\NrRepurpose\Domain\Enum\SourceType;
use Netresearch\NrRepurpose\Utility\ScalarCast;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/** Binds an assessment to the selected source, including the FAL file behind its counter. */
final readonly class SourceReviewPointer
{
    public function __construct(private ConnectionPool $connectionPool) {}

    /** @param array<string, mixed> $jobRow */
    public function fingerprint(array $jobRow): string
    {
        return $this->capture($jobRow)->hash;
    }

    /**
     * The fingerprint and the file of the first reference whose sys_file exists, the one
     * ingestion would pick, both from one read of the references (#238).
     *
     * @param array<string, mixed> $jobRow
     */
    public function capture(array $jobRow): SourceCapture
    {
        $fileUid                          = 0;
        $jobRow['_source_pdf_references'] = [];
        if (($jobRow['source_type'] ?? null) === SourceType::PdfFal->value) {
            $query      = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
            $expr       = $query->expr();
            $references = $query
                ->select('r.uid', 'r.uid_local', 'r.sorting_foreign', 'f.sha1', 'f.uid AS file_found')
                ->from('sys_file_reference', 'r')
                ->leftJoin(
                    'r',
                    'sys_file',
                    'f',
                    $expr->eq('f.uid', $query->quoteIdentifier('r.uid_local')),
                )
                ->where(
                    $expr->eq(
                        'r.uid_foreign',
                        $query->createNamedParameter(
                            ScalarCast::int($jobRow['uid'] ?? 0),
                            Connection::PARAM_INT,
                        ),
                    ),
                    $expr->eq(
                        'r.tablenames',
                        $query->createNamedParameter(
                            'tx_nrrepurpose_domain_model_job',
                        ),
                    ),
                    $expr->eq(
                        'r.fieldname',
                        $query->createNamedParameter('source_pdf'),
                    ),
                    $expr->eq(
                        'r.deleted',
                        $query->createNamedParameter(0, Connection::PARAM_INT),
                    ),
                )
                ->orderBy('r.sorting_foreign')
                ->addOrderBy('r.uid')
                ->executeQuery()
                ->fetchAllAssociative();
            $jobRow['_source_pdf_references'] = array_map(
                static fn (array $row): array => [
                    'uid'     => ScalarCast::int($row['uid']),
                    'file'    => ScalarCast::int($row['uid_local']),
                    'sorting' => ScalarCast::int($row['sorting_foreign']),
                    'sha1'    => ScalarCast::string($row['sha1']),
                ],
                $references,
            );
            foreach ($references as $reference) {
                if ($reference['file_found'] !== null) {
                    $fileUid = ScalarCast::int($reference['uid_local']);

                    break;
                }
            }
        }

        return new SourceCapture(SourceReviewPolicy::sourcePointerHash($jobRow), $fileUid);
    }
}
