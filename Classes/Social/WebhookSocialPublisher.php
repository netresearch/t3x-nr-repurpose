<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Social;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
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
 */
final readonly class WebhookSocialPublisher implements SocialPublisherInterface
{
    public const SIGNATURE_HEADER = 'X-Nr-Repurpose-Signature';

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private ExtensionConfiguration $extensionConfiguration,
        private LoggerInterface $logger,
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

        $body    = json_encode($post->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        $secret = $this->setting('socialWebhookSecret');
        if ($secret !== '') {
            $request = $request->withHeader(self::SIGNATURE_HEADER, 'sha256=' . hash_hmac('sha256', $body, $secret));
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // The client's message names the request URI, and a webhook URL can carry a
            // token; the refusal reason is stored and shown in the module, so it stays fixed.
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
