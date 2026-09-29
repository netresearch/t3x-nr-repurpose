<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use GuzzleHttp\ClientInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use TYPO3\CMS\Core\Resource\FileRepository;

/**
 * Resolves a job row to an absolute, locally readable PDF path:
 *  - pdf_fal: the file the job's sys_file_reference points at; on the local driver
 *    the stored file itself (never deleted), on any other driver a temp copy of its
 *    contents, which release() deletes.
 *  - pdf_url: the remote PDF is downloaded to a temp file, which release() deletes.
 */
class PdfFileResolver
{
    private const DOWNLOAD_PREFIX = 'nrrepurpose_dl_';

    private const JOB_TABLE = 'tx_nrrepurpose_domain_model_job';

    /** Driver key of TYPO3's local file system driver (sys_file_storage.driver). */
    private const LOCAL_DRIVER = 'Local';

    /** Largest PDF downloaded: 50 MiB covers long illustrated reports. */
    public const MAX_BYTES = 50 * 1024 * 1024;

    /** Total seconds for connecting and downloading one PDF. */
    public const TIMEOUT_SECONDS = 120.0;

    public function __construct(
        private readonly FileRepository $fileRepository,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly RemoteSourceGuard $guard,
    ) {}

    /** @param array<string,mixed> $jobRow */
    public function resolve(array $jobRow): string
    {
        $type = (string) ($jobRow['source_type'] ?? '');

        return match ($type) {
            'pdf_fal' => $this->resolveFalFile($jobRow),
            'pdf_url' => $this->downloadUrl((string) ($jobRow['source_value'] ?? '')),
            default   => throw new IngestionException('PdfFileResolver does not handle source_type: ' . $type, 1749379440),
        };
    }

    /**
     * Delete a temp copy this resolver wrote (a pdf_url download, or a pdf_fal file
     * from a non-local driver), once it has been read. Any other path is left alone:
     * for the local driver it is the editor's file in the storage itself.
     */
    public function release(string $absPath): void
    {
        if (dirname($absPath) !== sys_get_temp_dir()
            || !str_starts_with(basename($absPath), self::DOWNLOAD_PREFIX)
            || !is_file($absPath)
        ) {
            return;
        }

        // $absPath is a temp path writeDownload() generated, never user input.
        unlink($absPath); // nosemgrep: php.lang.security.unlink-use.unlink-use
    }

    /**
     * source_pdf is a TCA type=file field: its column holds the number of attached
     * files, the file itself hangs off a sys_file_reference row of the job.
     *
     * @param array<string,mixed> $jobRow
     */
    private function resolveFalFile(array $jobRow): string
    {
        $jobUid     = (int) ($jobRow['uid'] ?? 0);
        $references = $jobUid > 0 ? $this->fileRepository->findByRelation(self::JOB_TABLE, 'source_pdf', $jobUid) : [];
        // findByRelation() leaves out a reference whose sys_file no longer exists.
        if ($references === []) {
            throw new IngestionException('pdf_fal job has no attached PDF (source_pdf empty)', 1749379441);
        }

        $file    = $references[0]->getOriginalFile();
        $fileUid = $file->getUid();

        if ($file->getStorage()->getDriverType() !== self::LOCAL_DRIVER) {
            // Any other driver would copy the file into var/transient for local
            // processing, and nothing removes that copy. A copy of our own is
            // removed by release() once it has been read.
            return $this->writeDownload($file->getContents());
        }

        // The local driver returns the stored file itself; release() never touches it.
        $localPath = $file->getForLocalProcessing(false);
        if (!is_file($localPath)) {
            throw new IngestionException('Could not access attached PDF locally: ' . $fileUid, 1749379443);
        }

        return $localPath;
    }

    private function downloadUrl(string $url): string
    {
        if ($url === '') {
            throw new IngestionException('pdf_url job has an empty source_value', 1749379444);
        }

        $request = $this->guard->createRequest($this->requestFactory, 'GET', $url)
            ->withHeader('User-Agent', 'nr_repurpose/0.1 (+https://www.netresearch.de)')
            ->withHeader('Accept', 'application/pdf');

        try {
            $response = BoundedResponseReader::send($this->httpClient, $request, self::MAX_BYTES, self::TIMEOUT_SECONDS, $url);
        } catch (ClientExceptionInterface $e) {
            throw new IngestionException('PDF URL not reachable: ' . SourceUrlRedactor::redact($url), 1749379445, $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new IngestionException(sprintf('PDF URL returned HTTP %d: %s', $status, SourceUrlRedactor::redact($url)), 1749379446);
        }

        $bytes = BoundedResponseReader::read($response, self::MAX_BYTES, self::TIMEOUT_SECONDS, $url);
        if ($bytes === '') {
            throw new IngestionException('PDF URL returned an empty body: ' . SourceUrlRedactor::redact($url), 1749379447);
        }

        return $this->writeDownload($bytes);
    }

    /** Write the PDF to a temp file release() deletes; a partial file is removed at once. */
    private function writeDownload(string $bytes): string
    {
        $tmp = sys_get_temp_dir() . '/' . self::DOWNLOAD_PREFIX . bin2hex(random_bytes(6)) . '.pdf';
        if (!$this->writeFile($tmp, $bytes)) {
            if (is_file($tmp)) {
                // $tmp is the path generated one line above, never user input.
                unlink($tmp); // nosemgrep: php.lang.security.unlink-use.unlink-use
            }

            throw new IngestionException('Could not write the PDF to a temp file', 1749379448);
        }

        return $tmp;
    }

    /** True when every byte was written; a full disk writes part of them. */
    protected function writeFile(string $path, string $bytes): bool
    {
        return file_put_contents($path, $bytes) === strlen($bytes);
    }
}
