<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Social;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Netresearch\NrRepurpose\Social\SocialPost;
use Netresearch\NrRepurpose\Social\SocialPublishException;
use Netresearch\NrRepurpose\Social\WebhookSocialPublisher;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(WebhookSocialPublisher::class)]
final class WebhookSocialPublisherTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    private RecordingLogger $logger;

    private function publisher(array $settings, ResponseInterface|ClientExceptionInterface $answer = new Response(204)): WebhookSocialPublisher
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use ($answer): ResponseInterface {
            $this->requests[] = $request;
            if ($answer instanceof ClientExceptionInterface) {
                throw $answer;
            }

            return $answer;
        });

        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(static fn (string $extension, string $key): mixed => $settings[$key] ?? '');

        $factory = new HttpFactory();

        $this->logger = new RecordingLogger();

        return new WebhookSocialPublisher($client, $factory, $factory, $configuration, $this->logger);
    }

    private function post(): SocialPost
    {
        return new SocialPost(12, 3, 'linkedin', 'Revenue grew by 12 %. #growth', 1_790_000_000, 'https://example.com/report', ['aiGenerated' => true]);
    }

    public function testThePostIsSentAsSignedJson(): void
    {
        $this->publisher(['socialWebhookUrl' => 'https://hooks.example.com/social', 'socialWebhookSecret' => 's3cret'])->publish($this->post());

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        $body    = (string) $request->getBody();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://hooks.example.com/social', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('sha256=' . hash_hmac('sha256', $body, 's3cret'), $request->getHeaderLine(WebhookSocialPublisher::SIGNATURE_HEADER));
        self::assertSame([
            'artifactUid' => 12,
            'jobUid'      => 3,
            'platform'    => 'linkedin',
            'text'        => 'Revenue grew by 12 %. #growth',
            'publishAt'   => '2026-09-21T14:13:20Z',
            'sourceUrl'   => 'https://example.com/report',
            'aiGenerated' => true,
            'aiLabel'     => ['aiGenerated' => true],
        ], json_decode($body, true));
    }

    public function testWithoutASecretNoSignatureIsSent(): void
    {
        $this->publisher(['socialWebhookUrl' => 'https://hooks.example.com/social'])->publish($this->post());

        self::assertFalse($this->requests[0]->hasHeader(WebhookSocialPublisher::SIGNATURE_HEADER));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function urls(): iterable
    {
        yield 'https' => ['https://hooks.example.com/x', true];
        yield 'http' => ['http://localhost:8080/x', true];
        yield 'empty' => ['', false];
        yield 'file' => ['file:///etc/passwd', false];
        yield 'no scheme' => ['hooks.example.com/x', false];
    }

    #[DataProvider('urls')]
    public function testOnlyAnHttpUrlCountsAsAChannel(string $url, bool $configured): void
    {
        self::assertSame($configured, $this->publisher(['socialWebhookUrl' => $url])->isConfigured());
    }

    public function testAnAnswerOutside2xxIsARefusal(): void
    {
        $this->expectException(SocialPublishException::class);
        $this->expectExceptionMessage('Webhook answered HTTP 500');

        $this->publisher(['socialWebhookUrl' => 'https://hooks.example.com/x'], new Response(500))->publish($this->post());
    }

    /**
     * The refusal reason is stored as publish_error and shown to every module user, while
     * the client's message carries the request URI — including a token in the webhook URL.
     */
    public function testAnUnreachableWebhookIsARefusalWithoutTheClientMessage(): void
    {
        // Guzzle's ConnectException message, verbatim shape.
        $unreachable = new class ('cURL error 7: Failed to connect to hooks.example.com port 443 (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://hooks.example.com/x?token=s3cr3t-t0ken') extends RuntimeException implements ClientExceptionInterface {};

        try {
            $this->publisher(['socialWebhookUrl' => 'https://hooks.example.com/x?token=s3cr3t-t0ken'], $unreachable)->publish($this->post());
            self::fail('Expected a refusal');
        } catch (SocialPublishException $e) {
            self::assertSame('Webhook not reachable', $e->getMessage());
            self::assertStringNotContainsString('s3cr3t-t0ken', $e->getMessage());
            self::assertStringNotContainsString('cURL error', $e->getMessage());
            self::assertSame($unreachable, $e->getPrevious());
        }

        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::ERROR, $this->logger->records[0]['level']);
        self::assertSame($unreachable, $this->logger->records[0]['context']['exception'] ?? null);
    }

    public function testWithoutAUrlNothingIsSent(): void
    {
        try {
            $this->publisher([])->publish($this->post());
            self::fail('Expected a refusal');
        } catch (SocialPublishException) {
        }

        self::assertSame([], $this->requests);
    }
}
