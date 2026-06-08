<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Resolves a job row to an absolute, locally readable PDF path:
 *  - pdf_fal: the attached sys_file is fetched for local processing (ResourceFactory).
 *  - pdf_url: the remote PDF is downloaded to a temp file (PSR-18).
 */
class PdfFileResolver
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
    ) {}

    /** @param array<string,mixed> $jobRow */
    public function resolve(array $jobRow): string
    {
        $type = (string) ($jobRow['source_type'] ?? '');

        return match ($type) {
            'pdf_fal' => $this->resolveFalFile($jobRow),
            'pdf_url' => $this->downloadUrl((string) ($jobRow['source_value'] ?? '')),
            default => throw new IngestionException('PdfFileResolver does not handle source_type: ' . $type, 1749379440),
        };
    }

    /** @param array<string,mixed> $jobRow */
    private function resolveFalFile(array $jobRow): string
    {
        $fileUid = (int) ($jobRow['source_pdf'] ?? 0);
        if ($fileUid <= 0) {
            throw new IngestionException('pdf_fal job has no attached PDF (source_pdf empty)', 1749379441);
        }

        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (FileDoesNotExistException $e) {
            throw new IngestionException('Attached PDF sys_file not found: ' . $fileUid, 1749379442, $e);
        }

        // Local driver returns the real path; remote drivers copy to a temp file.
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

        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('User-Agent', 'nr_repurpose/0.1 (+https://www.netresearch.de)')
            ->withHeader('Accept', 'application/pdf');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new IngestionException('PDF URL not reachable: ' . $url, 1749379445, $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new IngestionException(sprintf('PDF URL returned HTTP %d: %s', $status, $url), 1749379446);
        }

        $bytes = (string) $response->getBody();
        if ($bytes === '') {
            throw new IngestionException('PDF URL returned an empty body: ' . $url, 1749379447);
        }

        $tmp = sys_get_temp_dir() . '/nrrepurpose_dl_' . bin2hex(random_bytes(6)) . '.pdf';
        if (file_put_contents($tmp, $bytes) === false) {
            throw new IngestionException('Could not write downloaded PDF to temp file', 1749379448);
        }

        return $tmp;
    }
}
