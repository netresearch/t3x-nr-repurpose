<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Request options and body reading shared by the two remote fetchers
 * (WebPageFetcher, PdfFileResolver), so a remote source can neither hang the
 * worker nor fill its memory.
 *
 * The container's client carries $GLOBALS['TYPO3_CONF_VARS']['HTTP'], whose
 * `timeout` is 0 (no limit) and whose `allow_redirects` follows up to five
 * redirects. PSR-18 sendRequest() cannot take per-request options, so the
 * fetchers call Guzzle's send() with these options instead.
 *
 * With `stream` Guzzle hands the request to its stream handler, where
 * `timeout` limits each wait for data, not the whole transfer. read() therefore
 * also stops at the same number of seconds overall, so a server sending one
 * byte at a time cannot hold the worker.
 */
final class BoundedResponseReader
{
    private const CHUNK_BYTES = 8192;

    /**
     * Per-request Guzzle options: a read and a connect timeout, the body left
     * unread until read() pulls it, no redirects (a redirect target would bypass
     * RemoteSourceGuard) and no exception for a non-2xx status (the fetchers
     * report it themselves).
     *
     * @return array<string, mixed>
     */
    public static function requestOptions(float $timeoutSeconds): array
    {
        return [
            RequestOptions::TIMEOUT         => $timeoutSeconds,
            RequestOptions::CONNECT_TIMEOUT => 10.0,
            RequestOptions::READ_TIMEOUT    => $timeoutSeconds,
            RequestOptions::STREAM          => true,
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::HTTP_ERRORS     => false,
        ];
    }

    /**
     * The body as a string, read in chunks and abandoned as soon as it exceeds
     * $maxBytes or takes longer than $timeoutSeconds. A declared Content-Length
     * above the limit fails before reading.
     *
     * @throws IngestionException when the body is too large, too slow or unreadable
     */
    public static function read(ResponseInterface $response, int $maxBytes, float $timeoutSeconds, string $url): string
    {
        $declared = $response->getHeaderLine('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $maxBytes) {
            $response->getBody()->close();

            throw self::tooLarge($maxBytes, $url);
        }

        $body     = $response->getBody();
        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1e9);
        $buffer   = '';
        try {
            while (true) {
                $chunk = $body->read(self::CHUNK_BYTES);
                if ($chunk === '') {
                    if ($body->eof()) {
                        break;
                    }

                    // A blocking read returns nothing before the end only when it timed out.
                    throw self::timedOut($timeoutSeconds, $url);
                }

                if (hrtime(true) > $deadline) {
                    throw self::timedOut($timeoutSeconds, $url);
                }

                $buffer .= $chunk;
                if (strlen($buffer) > $maxBytes) {
                    throw self::tooLarge($maxBytes, $url);
                }
            }
        } catch (RuntimeException $e) {
            // The stream handler reports an expired read timeout as a failed read;
            // the flag is gone once the stream is closed.
            $timedOut = $body->getMetadata('timed_out') === true;
            $body->close();

            if ($e instanceof IngestionException) {
                throw $e;
            }

            if ($timedOut) {
                throw self::timedOut($timeoutSeconds, $url);
            }

            throw new IngestionException('Reading the source failed: ' . $url, 1749379466, $e);
        }

        return $buffer;
    }

    private static function timedOut(float $timeoutSeconds, string $url): IngestionException
    {
        return new IngestionException(
            sprintf('Source did not arrive within %d seconds: %s', (int) $timeoutSeconds, $url),
            1749379465,
        );
    }

    private static function tooLarge(int $maxBytes, string $url): IngestionException
    {
        return new IngestionException(
            sprintf('Source is larger than %d MiB: %s', intdiv($maxBytes, 1024 * 1024), $url),
            1749379464,
        );
    }
}
