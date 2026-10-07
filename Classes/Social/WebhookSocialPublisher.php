<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Social;

use GuzzleHttp\ClientInterface;
use JsonException;
use Netresearch\NrRepurpose\Ingestion\BoundedResponseReader;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\RemoteSourceGuard;
use Netresearch\NrRepurpose\Ingestion\SourceUrlRedactor;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Sends a due post as JSON by HTTP POST to the URL in the extension setting
 * `socialWebhookUrl`. With `socialWebhookSecret` set, the body is signed:
 * header `X-Nr-Repurpose-Signature: sha256=<hex HMAC-SHA256 of the body>`.
 * A 2xx answer counts as accepted.
 *
 * The URL passes the same check as a source URL (RemoteSourceGuard: http or https,
 * a host whose every address is public), the request connects to the checked
 * addresses, follows no redirect and is limited in time (BoundedResponseReader).
 * Every refusal is a SocialPublishException with a fixed text: the reason is stored
 * and shown in the module, and a webhook URL can carry a token.
 */
final readonly class WebhookSocialPublisher implements SocialPublisherInterface
{
    public const SIGNATURE_HEADER = 'X-Nr-Repurpose-Signature';

    /** Total seconds for connecting, sending the post and receiving the answer. */
    public const TIMEOUT_SECONDS = 15.0;

    /** Largest answer accepted; only its status is used. */
    private const MAX_ANSWER_BYTES = 64 * 1024;

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private ExtensionConfiguration $extensionConfiguration,
        private LoggerInterface $logger,
        private RemoteSourceGuard $guard,
    ) {}

    public function isConfigured(): bool
    {
        return $this->url() !== '';
    }

    public function publish(SocialPost $post): void
    {
        $url = $this->url();
        if ($url === '') {
            throw new SocialPublishException('No publishing channel configured (socialWebhookUrl)', 1790410001);
        }

        try {
            $body = json_encode($post->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            $this->logger->error('Social post could not be encoded as JSON', ['artifact' => $post->artifactUid, 'exception' => $e]);

            throw new SocialPublishException('The post could not be encoded as JSON', 1790410005, $e);
        }

        try {
            $guarded = $this->guard->createRequest($this->requestFactory, 'POST', $url);
        } catch (IngestionException $e) {
            // The guard's message names the host; the log gets the URL without credentials and query.
            $this->logger->error('Social webhook URL refused', ['url' => SourceUrlRedactor::redact($url), 'exception' => $e]);

            throw new SocialPublishException('Webhook URL refused: only http and https to a host on the public internet are allowed', 1790410004, $e);
        }

        $request = $guarded
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        $secret = $this->setting('socialWebhookSecret');
        if ($secret !== '') {
            $request = $request->withHeader(self::SIGNATURE_HEADER, 'sha256=' . hash_hmac('sha256', $body, $secret));
        }

        try {
            $response = BoundedResponseReader::send($this->httpClient, $request, self::MAX_ANSWER_BYTES, self::TIMEOUT_SECONDS, $url);
        } catch (Throwable $e) {
            // The client's and the reader's messages name the request URI, and a webhook URL
            // can carry a token; the refusal reason is stored and shown in the module.
            $this->logger->error('Social webhook not reachable', ['exception' => $e]);

            throw new SocialPublishException('Webhook not reachable', 1790410002, $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            throw new SocialPublishException(sprintf('Webhook answered HTTP %d', $status), 1790410003);
        }
    }

    /** The configured URL when it is an http(s) URL, else ''. */
    private function url(): string
    {
        $url    = $this->setting('socialWebhookUrl');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    private function setting(string $key): string
    {
        try {
            $value = $this->extensionConfiguration->get('nr_repurpose', $key);
        } catch (Throwable) {
            return '';
        }

        return is_string($value) ? trim($value) : '';
    }
}
