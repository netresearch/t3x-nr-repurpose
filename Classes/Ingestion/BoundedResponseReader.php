<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Ingestion;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\InflateStream;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Sending and body reading shared by the two remote fetchers (WebPageFetcher,
 * PdfFileResolver), so a remote source can neither hang the worker nor fill its
 * memory.
 *
 * The container's client carries $GLOBALS['TYPO3_CONF_VARS']['HTTP'], whose
 * `timeout` is 0 (no limit) and whose `allow_redirects` follows up to five
 * redirects. PSR-18 sendRequest() cannot take per-request options, so the
 * fetchers go through send() here with limits of their own.
 *
 * The transfer is not streamed, so Guzzle picks its curl handler when ext-curl
 * is loaded. There `timeout` is one limit for the whole transfer, headers
 * included, `connect_timeout` applies, and curl refuses response headers above
 * its size cap. The progress callback stops the download above the size limit
 * or after the timeout on every handler. Without ext-curl Guzzle falls back to
 * its stream handler, which bounds the body the same way but applies `timeout`
 * to each read only and has no header size cap: there a server that sends its
 * headers slowly or endlessly can still hold the worker or exhaust memory_limit.
 */
final class BoundedResponseReader
{
    private const CHUNK_BYTES = 8192;

    private const CONNECT_TIMEOUT_SECONDS = 10.0;

    /** CURLE_OPERATION_TIMEDOUT: curl's `timeout` expired. */
    private const CURL_TIMED_OUT = 28;

    /**
     * Per-request Guzzle options: a total and a connect timeout, the size and time
     * limits enforced while the body arrives, no redirects (a redirect target would
     * bypass RemoteSourceGuard), no exception for a non-2xx status (the fetchers
     * report it themselves) and no decoding by the handler (the progress callback
     * counts bytes on the wire; read() inflates within the limit).
     *
     * @return array<string, mixed>
     */
    public static function requestOptions(int $maxBytes, float $timeoutSeconds, string $url): array
    {
        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1e9);

        return [
            RequestOptions::TIMEOUT         => $timeoutSeconds,
            RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            RequestOptions::READ_TIMEOUT    => $timeoutSeconds,
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::HTTP_ERRORS     => false,
            RequestOptions::DECODE_CONTENT  => false,
            RequestOptions::ON_HEADERS      => static function (ResponseInterface $response) use ($maxBytes, $url): void {
                if (self::declaresMoreThan($response, $maxBytes)) {
                    throw self::tooLarge($maxBytes, $url);
                }
            },
            RequestOptions::PROGRESS => static function (int $downloadTotal, int $downloaded) use ($maxBytes, $deadline, $timeoutSeconds, $url): void {
                if ($downloaded > $maxBytes) {
                    throw self::tooLarge($maxBytes, $url);
                }

                if (hrtime(true) > $deadline) {
                    throw self::timedOut($timeoutSeconds, $url);
                }
            },
        ];
    }

    /**
     * send() with requestOptions(). A limit the handler hit surfaces as the
     * IngestionException that names it; every other transport error reaches the
     * caller unchanged.
     *
     * @throws IngestionException when the source is too large or too slow
     * @throws GuzzleException    when the source cannot be reached
     */
    public static function send(ClientInterface $client, RequestInterface $request, int $maxBytes, float $timeoutSeconds, string $url): ResponseInterface
    {
        try {
            return $client->send($request, self::requestOptions($maxBytes, $timeoutSeconds, $url));
        } catch (RequestException|ConnectException $e) {
            // The stream and mock handlers wrap an exception thrown by on_headers or
            // progress; curl reports its own expired `timeout` as errno 28.
            for ($cause = $e->getPrevious(); $cause instanceof Throwable; $cause = $cause->getPrevious()) {
                if ($cause instanceof IngestionException) {
                    throw $cause;
                }
            }

            if (($e->getHandlerContext()['errno'] ?? null) === self::CURL_TIMED_OUT) {
                throw self::timedOut($timeoutSeconds, $url);
            }

            throw $e;
        }
    }

    /**
     * The body as a string, read in chunks and abandoned as soon as it exceeds
     * $maxBytes or takes longer than $timeoutSeconds. A gzip or deflate body is
     * inflated here and the limit applies to the inflated size. A declared
     * Content-Length above the limit fails before reading.
     *
     * @throws IngestionException when the body is too large, too slow or unreadable
     */
    public static function read(ResponseInterface $response, int $maxBytes, float $timeoutSeconds, string $url): string
    {
        if (self::declaresMoreThan($response, $maxBytes)) {
            $response->getBody()->close();

            throw self::tooLarge($maxBytes, $url);
        }

        $body = $response->getBody();
        if (in_array(strtolower(trim($response->getHeaderLine('Content-Encoding'))), ['gzip', 'deflate'], true)) {
            $body = new InflateStream($body);
        }

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

    private static function declaresMoreThan(ResponseInterface $response, int $maxBytes): bool
    {
        $declared = $response->getHeaderLine('Content-Length');

        return $declared !== '' && ctype_digit($declared) && (int) $declared > $maxBytes;
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
