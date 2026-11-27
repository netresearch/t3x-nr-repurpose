<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Persistence;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactStatus;
use Netresearch\NrRepurpose\Domain\Enum\ArtifactType;
use Netresearch\NrRepurpose\Domain\Enum\FailureReason;
use Netresearch\NrRepurpose\Domain\Enum\JobStatus;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceReport;
use Netresearch\NrRepurpose\Exception\ArtifactRowMissingException;
use Netresearch\NrRepurpose\Exception\RunOwnershipLostException;
use Netresearch\NrRepurpose\Pipeline\RunHeartbeatInterface;
use Netresearch\NrRepurpose\Utility\ScalarCast;
use Random\Randomizer;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Plain DBAL persistence for the long-running Messenger worker.
 * Avoids Extbase persistence-session pitfalls in a recycled consumer process.
 */
class JobProcessingRepository implements RunHeartbeatInterface
{
    private const string JOB_TABLE = 'tx_nrrepurpose_domain_model_job';

    private const string ARTIFACT_TABLE = 'tx_nrrepurpose_domain_model_artifact';

    private const string REFERENCE_TABLE = 'sys_file_reference';

    private const string SOURCE_PDF_FIELD = 'source_pdf';

    /**
     * How long a run's claim on a job holds without a write of that run (#238).
     *
     * A worker that dies leaves its message in the doctrine transport, which hands it out again
     * once its redeliver_timeout has passed (3600 s: TYPO3 passes only queue_name, so Symfony's
     * default applies). That delivery can take the job over only when the claim has expired by
     * then, so the lease has to be well below 3600 s. A live run must not lose its claim
     * between two renewals: every write of the run renews the claim, and so does renew() before
     * every PDF page and every map-reduce chunk, the stretches that write nothing to the job.
     * The longest step between two renewals is then one provider call, OCR page, chunk or ffmpeg
     * slideshow, bounded by 300 s (the HTTP timeout the worker documentation asks for, the image
     * request, the slideshow's process limit). 900 s is three of those.
     */
    public const int RUN_LEASE_SECONDS = 900;

    /**
     * The token of the run this process holds, per job uid. A worker runs one message at a
     * time; two runs of one job are two processes, each with its own repository instance.
     *
     * @var array<int, string>
     */
    private array $runTokens = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    /**
     * Claim the job for this run: one statement that only an unfinished job passes whose claim
     * is empty or older than the lease, so of two runs one wins (#238). The winner's later
     * writes are bound to its token; a run that does not win writes nothing.
     */
    public function claimRun(int $jobUid): bool
    {
        $token        = bin2hex((new Randomizer())->getBytes(16));
        $now          = time();
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::JOB_TABLE);
        $expr         = $queryBuilder->expr();
        $claimed      = $queryBuilder
            ->update(self::JOB_TABLE)
            ->set('run_token', $token)
            ->set('run_claimed_at', $now)
            ->where(
                $expr->eq('uid', $queryBuilder->createNamedParameter($jobUid, Connection::PARAM_INT)),
                $expr->notIn(
                    'status',
                    $queryBuilder->createNamedParameter(
                        array_map(static fn (JobStatus $s): string => $s->value, array_values(array_filter(JobStatus::cases(), static fn (JobStatus $s): bool => $s->isTerminal()))),
                        Connection::PARAM_STR_ARRAY,
                    ),
                ),
                $this->freeClaim($queryBuilder, $now),
            )
            ->executeStatement() === 1;

        if ($claimed) {
            $this->runTokens[$jobUid] = $token;
        }

        return $claimed;
    }

    /**
     * Renew this process's claim in a long stretch without a write to the job: per OCR page,
     * per map-reduce chunk (#238). Without a claim it does nothing.
     *
     * @throws RunOwnershipLostException
     */
    public function renew(int $jobUid): void
    {
        $this->renewClaim($jobUid);
    }

    /** Whether no live run holds the job: no claim, or one older than the lease. */
    private function claimIsFree(int $jobUid): bool
    {
        $row = $this->findRow($jobUid);

        return $row === null
            || ScalarCast::string($row['run_token'] ?? '') === ''
            || ScalarCast::int($row['run_claimed_at'] ?? 0) < time() - self::RUN_LEASE_SECONDS;
    }

    private function freeClaim(QueryBuilder $queryBuilder, int $now): string
    {
        $expr = $queryBuilder->expr();

        return (string) $expr->or(
            $expr->eq('run_token', $queryBuilder->createNamedParameter('')),
            $expr->lt('run_claimed_at', $queryBuilder->createNamedParameter($now - self::RUN_LEASE_SECONDS, Connection::PARAM_INT)),
        );
    }

    /** Drop this process's token for the job once its run has ended; writes nothing. */
    public function forgetRun(int $jobUid): void
    {
        unset($this->runTokens[$jobUid]);
    }

    /**
     * Update the job row. Under a claim of this process, only while the row still carries its
     * token, and the write renews the claim. Without a claim (the backend, a test) by uid alone.
     *
     * A terminal write ($release) also clears the claim: a failed job that is queued again
     * starts with no claim on it.
     *
     * @param array<string, mixed> $fields
     *
     * @throws RunOwnershipLostException
     */
    private function updateJob(int $jobUid, array $fields, bool $release = false): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::JOB_TABLE);
        $token      = $this->runTokens[$jobUid] ?? null;
        if ($token === null) {
            $connection->update(self::JOB_TABLE, $fields, ['uid' => $jobUid]);

            return;
        }

        $fields += $release ? ['run_token' => '', 'run_claimed_at' => 0] : ['run_claimed_at' => time()];
        if ($connection->update(self::JOB_TABLE, $fields, ['uid' => $jobUid, 'run_token' => $token]) === 0) {
            $this->assertOwnership($jobUid, $token);
        }
    }

    /**
     * Renew this process's claim before a write to another table (artifact rows, FAL files):
     * after it, the claim cannot expire for a lease, so no other run takes the job over
     * before the write that follows.
     *
     * @throws RunOwnershipLostException
     */
    private function renewClaim(int $jobUid): void
    {
        if (isset($this->runTokens[$jobUid])) {
            $this->updateJob($jobUid, ['run_claimed_at' => time()]);
        }
    }

    /**
     * MySQL and MariaDB report changed rather than matched rows, so a write within the same
     * second that changes nothing reports 0 although the row carries the token: read it.
     *
     * @throws RunOwnershipLostException
     */
    private function assertOwnership(int $jobUid, string $token): void
    {
        // The token stays with the run: every later write of it fails the same way.
        if (($this->findRow($jobUid)['run_token'] ?? null) !== $token) {
            throw new RunOwnershipLostException(sprintf('Another run holds job %d now', $jobUid), 1795000001);
        }
    }

    /** @return array<string, mixed>|null */
    public function findRow(int $jobUid): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::JOB_TABLE)
            ->select(['*'], self::JOB_TABLE, ['uid' => $jobUid])
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    public function markStatus(int $jobUid, JobStatus $status, ?string $currentStep = null, ?int $progress = null): void
    {
        $fields = ['status' => $status->value, 'tstamp' => time()];
        if ($currentStep !== null) {
            $fields['current_step'] = $currentStep;
        }

        if ($progress !== null) {
            $fields['progress'] = $progress;
        }

        $this->updateJob($jobUid, $fields);
    }

    /**
     * End a generation run: final status, its error message ('' clears one from an
     * earlier run), and the artifacts counter. The counter is the inline field's
     * column; DataHandler keeps it for records it writes, and the worker writes
     * artifact rows directly, so the rows are counted here.
     */
    public function finishJob(int $jobUid, JobStatus $status, string $error, ?FailureReason $reason = null): void
    {
        $this->renewClaim($jobUid);
        $artifacts = $this->connectionPool->getConnectionForTable(self::ARTIFACT_TABLE)
            ->count('uid', self::ARTIFACT_TABLE, ['job' => $jobUid]);

        $this->updateJob(
            $jobUid,
            [
                'status'         => $status->value,
                'current_step'   => 'done',
                'progress'       => 100,
                'error_message'  => $error,
                'failure_reason' => $reason->value ?? '',
                'artifacts'      => $artifacts,
                'tstamp'         => time(),
            ],
            true,
        );
        $this->forgetRun($jobUid);
    }

    /**
     * Without a claim of this process (the Messenger handler's catch for a failure before the
     * claim), only a job that no live run holds is marked failed; a run that holds one owns
     * its outcome (#238).
     */
    public function markFailed(int $jobUid, string $error, FailureReason $reason): void
    {
        if (!isset($this->runTokens[$jobUid])) {
            $now          = time();
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::JOB_TABLE);
            $queryBuilder
                ->update(self::JOB_TABLE)
                ->set('status', JobStatus::Failed->value)
                ->set('error_message', $error)
                ->set('failure_reason', $reason->value)
                ->set('tstamp', $now)
                ->set('run_token', '')
                ->set('run_claimed_at', 0)
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($jobUid, Connection::PARAM_INT)),
                    $this->freeClaim($queryBuilder, $now),
                )
                ->executeStatement();

            return;
        }

        $this->updateJob(
            $jobUid,
            ['status' => JobStatus::Failed->value, 'error_message' => $error, 'failure_reason' => $reason->value, 'tstamp' => time()],
            true,
        );
        $this->forgetRun($jobUid);
    }

    /**
     * Back into the queue. One statement that only a failed job passes, so of two clicks, or of
     * two people, one run starts: the caller dispatches only when this returns true.
     */
    public function requeueFailed(int $jobUid): bool
    {
        return $this->connectionPool->getConnectionForTable(self::JOB_TABLE)->update(
            self::JOB_TABLE,
            [
                'status'            => JobStatus::Queued->value,
                'current_step'      => '',
                'progress'          => 0,
                'error_message'     => '',
                'failure_reason'    => '',
                'source_report'     => '',
                'language_detected' => '',
                'tstamp'            => time(),
                'run_token'         => '',
                'run_claimed_at'    => 0,
            ],
            ['uid' => $jobUid, 'status' => JobStatus::Failed->value],
        ) === 1;
    }

    public function setLanguageDetected(int $jobUid, string $language): void
    {
        $this->updateJob($jobUid, ['language_detected' => $language, 'tstamp' => time()]);
    }

    /** What a page source used, left out or was cut by; the result view shows it (ADR-010). */
    public function setSourceReport(int $jobUid, SourceReport $report): void
    {
        $this->updateJob($jobUid, ['source_report' => $report->toJson(), 'tstamp' => time()]);
    }

    /**
     * Attach the file a backend user picked in the new-job form, stored as DataHandler stores a
     * TCA type=file field: a sys_file_reference row of the job, the number of references in the
     * job's column, and the reference index, so the Filelist module warns before deleting it.
     */
    public function attachSourcePdf(int $jobUid, int $fileUid): void
    {
        $now  = time();
        $conn = $this->connectionPool->getConnectionForTable(self::REFERENCE_TABLE);
        $conn->insert(self::REFERENCE_TABLE, [
            // A job lies on the page the form was opened on; its reference lies with it.
            'pid'             => ScalarCast::int($this->findRow($jobUid)['pid'] ?? 0),
            'uid_local'       => $fileUid,
            'uid_foreign'     => $jobUid,
            'tablenames'      => self::JOB_TABLE,
            'fieldname'       => self::SOURCE_PDF_FIELD,
            'sorting_foreign' => 1,
            'crdate'          => $now,
            'tstamp'          => $now,
        ]);

        $referenceUid = (int) $conn->lastInsertId();

        $this->connectionPool->getConnectionForTable(self::JOB_TABLE)
            ->update(self::JOB_TABLE, [self::SOURCE_PDF_FIELD => 1, 'tstamp' => $now], ['uid' => $jobUid]);
        // Core offers no public API for this short of DataHandler, which an editor's table
        // permissions would refuse here.
        /** @phpstan-ignore classConstant.internalClass */
        $referenceIndex = GeneralUtility::makeInstance(ReferenceIndex::class);
        /** @phpstan-ignore method.internal */
        $referenceIndex->updateRefIndexTable(self::JOB_TABLE, $jobUid);
        /** @phpstan-ignore method.internal */
        $referenceIndex->updateRefIndexTable(self::REFERENCE_TABLE, $referenceUid);
    }

    public function insertArtifact(
        int $jobUid,
        ArtifactType $type,
        string $variant,
        int $fileUid,
        ArtifactStatus $status,
        ?string $error = null,
    ): int {
        $this->renewClaim($jobUid);
        $conn = $this->connectionPool->getConnectionForTable(self::ARTIFACT_TABLE);
        $conn->insert(self::ARTIFACT_TABLE, [
            'pid'           => 0,
            'job'           => $jobUid,
            'type'          => $type->value,
            'variant'       => $variant,
            'file_uid'      => $fileUid,
            'status'        => $status->value,
            'error_message' => $error ?? '',
            'crdate'        => time(),
            'tstamp'        => time(),
        ]);

        return (int) $conn->lastInsertId();
    }

    /**
     * Remove all artifact rows for a job before a fresh generation run. Generators append a row
     * per (type, variant), so reprocessing — e.g. a Messenger redelivery after a mid-run worker
     * crash, or a manual re-queue — would otherwise accumulate a second set of rows and the
     * result view would show duplicates. Clearing first makes generation idempotent.
     *
     * The referenced FAL files (sys_file + physical bytes) are removed first so a re-run does not
     * leak orphaned files/records. Permission evaluation is disabled for the delete because the
     * worker runs in a CLI context with no backend user (mirrors JobFileStorage::store()).
     */
    public function deleteArtifactsForJob(int $jobUid): void
    {
        // Under a claim, only the run that holds it clears the set. A retry from the backend
        // runs without one and leaves a set alone that a live run holds (#238).
        if (isset($this->runTokens[$jobUid])) {
            $this->renewClaim($jobUid);
        } elseif (!$this->claimIsFree($jobUid)) {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::ARTIFACT_TABLE);

        /** @var list<array{file_uid: int|string|null, subtitle_file_uid: int|string|null}> $rows */
        $rows = $connection->select(['file_uid', 'subtitle_file_uid'], self::ARTIFACT_TABLE, ['job' => $jobUid])
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            foreach (['file_uid', 'subtitle_file_uid'] as $column) {
                $this->deleteFalFile((int) ($row[$column] ?? 0));
            }
        }

        $connection->delete(self::ARTIFACT_TABLE, ['job' => $jobUid]);
        $this->updateJob($jobUid, ['artifacts' => 0]);
    }

    /**
     * Delete one FAL file by sys_file uid, tolerating an already-removed/unresolvable file.
     * Permission evaluation is toggled off for the trusted system delete and restored afterwards
     * so the shared (cached) storage instance is not left mutated.
     */
    private function deleteFalFile(int $fileUid): void
    {
        if ($fileUid <= 0) {
            return;
        }

        try {
            $file                        = $this->resourceFactory->getFileObject($fileUid);
            $storage                     = $file->getStorage();
            $previousEvaluatePermissions = $storage->getEvaluatePermissions();
            $storage->setEvaluatePermissions(false);
            try {
                $storage->deleteFile($file);
            } finally {
                $storage->setEvaluatePermissions($previousEvaluatePermissions);
            }
        } catch (Throwable) {
            // File already deleted or unresolvable — the artifact row delete still cleans up.
        }
    }

    /**
     * Fill a previously-inserted (pending) artifact row. Only whitelisted columns are writable.
     * Empty $fields is a no-op (no UPDATE issued).
     *
     * @param array<string, mixed> $fields keys: file_uid, subtitle_file_uid, source_html,
     *                                     script_text, metadata, status, variant, error_message,
     *                                     failure_reason
     */
    public function updateArtifact(int $artifactUid, array $fields): void
    {
        $allowed = [
            'file_uid',
            'subtitle_file_uid',
            'source_html',
            'script_text',
            'metadata',
            'source_review',
            'status',
            'variant',
            'error_message',
            'failure_reason',
        ];
        $update = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $fields)) {
                $update[$column] = $fields[$column];
            }
        }

        if ($update === []) {
            return;
        }

        $jobUid = ScalarCast::int(
            $this->connectionPool->getConnectionForTable(self::ARTIFACT_TABLE)
                ->select(['job'], self::ARTIFACT_TABLE, ['uid' => $artifactUid])
                ->fetchOne(),
        );
        if ($jobUid === 0) {
            // The row is gone: a run that took the job over cleared the set, or a retry did.
            // The file meant for it is nobody's. A run of this process that lost its job stops
            // here; one that still holds it records the failure instead of a silent success.
            foreach (['file_uid', 'subtitle_file_uid'] as $column) {
                $this->deleteFalFile(ScalarCast::int($update[$column] ?? 0));
            }

            foreach ($this->runTokens as $heldJob => $token) {
                $this->assertOwnership($heldJob, $token);
            }

            throw new ArtifactRowMissingException(sprintf('Artifact %d no longer exists', $artifactUid), 1795000003);
        }

        try {
            $this->renewClaim($jobUid);
        } catch (RunOwnershipLostException $e) {
            // The file this run stored for the row is nobody's now: the run that holds the job
            // clears the rows and writes its own files.
            foreach (['file_uid', 'subtitle_file_uid'] as $column) {
                $this->deleteFalFile(ScalarCast::int($update[$column] ?? 0));
            }

            throw $e;
        }

        $update['tstamp'] = time();

        $this->connectionPool->getConnectionForTable(self::ARTIFACT_TABLE)
            ->update(self::ARTIFACT_TABLE, $update, ['uid' => $artifactUid]);
    }

    /** @param array<string, mixed> $review */
    public function setSourceReview(int $jobUid, array $review): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::JOB_TABLE)
            ->update(
                self::JOB_TABLE,
                [
                    'source_review' => json_encode(
                        $review,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                    ),
                    'tstamp' => time(),
                ],
                ['uid' => $jobUid],
            );
    }

    /**
     * Store only against the exact review read before the potentially slow evaluation.
     * SQL NULL is a distinct snapshot; concurrent stronger verdicts must survive.
     *
     * @param array<string, mixed> $review
     */
    public function compareAndSetSourceReview(
        int $jobUid,
        array $review,
        ?string $expected,
    ): bool {
        $encoded      = json_encode($review, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::JOB_TABLE);
        $queryBuilder
            ->update(self::JOB_TABLE)
            ->set('source_review', $encoded)
            ->set('tstamp', time())
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter(
                        $jobUid,
                        Connection::PARAM_INT,
                    ),
                ),
            );
        $token = $this->runTokens[$jobUid] ?? null;
        if ($token !== null) {
            $queryBuilder
                ->set('run_claimed_at', time())
                ->andWhere($queryBuilder->expr()->eq('run_token', $queryBuilder->createNamedParameter($token)));
        }

        if ($expected === null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->isNull('source_review'),
            );
        } else {
            $parameter = $queryBuilder->createNamedParameter($expected);
            // MySQL's default text collation ignores case. Compare the raw snapshot bytes.
            $condition = $this->connectionPool
                ->getConnectionForTable(self::JOB_TABLE)
                ->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'BINARY ' . $queryBuilder->quoteIdentifier('source_review') . ' = BINARY ' . $parameter : $queryBuilder->expr()->eq('source_review', $parameter);
            $queryBuilder->andWhere($condition);
        }

        if ($queryBuilder->executeStatement() > 0) {
            return true;
        }

        if ($token !== null) {
            $this->assertOwnership($jobUid, $token);
        }

        // Some drivers report changed rather than matched rows for an identical write.
        return $encoded === $expected && ($this->findRow($jobUid)['source_review'] ?? null) === $expected;
    }
}
