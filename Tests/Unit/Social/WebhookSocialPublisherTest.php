<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Social;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Netresearch\NrRepurpose\Ingestion\RemoteSourceGuard;
use Netresearch\NrRepurpose\Social\SocialPost;
use Netresearch\NrRepurpose\Social\SocialPublishException;
use Netresearch\NrRepurpose\Social\WebhookSecretResolver;
use Netresearch\NrRepurpose\Social\WebhookSocialPublisher;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\QueuedHttpClient;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StaticHostResolver;
use Netresearch\NrVault\Exception\AccessDeniedException;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(WebhookSocialPublisher::class)]
final class WebhookSocialPublisherTest extends TestCase
{
    private QueuedHttpClient $http;

    private RecordingLogger $logger;

    /** @var list<int> backend user uids the vault read ran as */
    private array $actors = [];

    /**
     * @param array<string, string>                $settings
     * @param array<string, string|Throwable|null> $vault    identifier => secret, or what retrieve() throws
     */
    private function publisher(array $settings, ResponseInterface|Throwable $answer = new Response(204), ?RemoteSourceGuard $guard = null, array $vault = []): WebhookSocialPublisher
    {
        $this->http = new QueuedHttpClient($answer);
        // A transport failure goes through a handler that rejects the way the curl handler
        // does; Guzzle 7's MockHandler would also hand the exception to on_headers.
        $client = $answer instanceof Throwable
            ? new Client(['handler' => HandlerStack::create(static fn (): PromiseInterface => Create::rejectionFor($answer))])
            : $this->http->client;

        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(static fn (string $extension, string $key): mixed => $settings[$key] ?? '');

        $vaultService = $this->createStub(VaultServiceInterface::class);
        $vaultService->method('retrieve')->willReturnCallback(static function (string $identifier) use ($vault): ?string {
            $secret = $vault[$identifier] ?? null;
            if ($secret instanceof Throwable) {
                throw $secret;
            }

            return $secret;
        });

        $technicalActor = $this->createStub(TechnicalActorContextInterface::class);
        $technicalActor->method('runAs')->willReturnCallback(function (int $uid, callable $fn): mixed {
            $this->actors[] = $uid;

            return $fn();
        });

        $factory = new HttpFactory();

        $this->logger = new RecordingLogger();

        return new WebhookSocialPublisher(
            $client,
            $factory,
            $factory,
            $configuration,
            $this->logger,
            $guard ?? StaticHostResolver::publicGuard(),
            new WebhookSecretResolver($vaultService, $technicalActor, $configuration, $this->logger),
        );
    }

    private function sentRequest(): RequestInterface
    {
        self::assertSame(0, $this->http->unconsumed(), 'one request was sent');
        $request = $this->http->handler->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }

    private function post(): SocialPost
    {
        return new SocialPost(12, 3, 'linkedin', 'Revenue grew by 12 %. #growth', 1_790_000_000, 'https://example.com/report', ['aiGenerated' => true]);
    }

    public function testThePostIsSentAsSignedJson(): void
    {
        $this->publisher(
            ['socialWebhookUrl' => 'https://hooks.example.com/social', 'socialWebhookSecretIdentifier' => 'nr_repurpose_webhook'],
            vault: ['nr_repurpose_webhook' => 's3cret'],
        )->publish($this->post());

        $request = $this->sentRequest();
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

    public function testTheSecretIsReadAsTheTechnicalBackendUser(): void
    {
        $this->publisher(
            ['socialWebhookUrl' => 'https://hooks.example.com/social', 'socialWebhookSecretIdentifier' => 'nr_repurpose_webhook', 'technicalBeUserUid' => '9'],
            vault: ['nr_repurpose_webhook' => 's3cret'],
        )->publish($this->post());

        self::assertSame([9], $this->actors);
        $request = $this->sentRequest();
        self::assertSame('sha256=' . hash_hmac('sha256', (string) $request->getBody(), 's3cret'), $request->getHeaderLine(WebhookSocialPublisher::SIGNATURE_HEADER));
    }

    public function testWithoutATechnicalBackendUserTheSecretIsReadDirectly(): void
    {
        $this->publisher(
            ['socialWebhookUrl' => 'https://hooks.example.com/social', 'socialWebhookSecretIdentifier' => 'nr_repurpose_webhook'],
            vault: ['nr_repurpose_webhook' => 's3cret'],
        )->publish($this->post());

        self::assertSame([], $this->actors);
        self::assertTrue($this->sentRequest()->hasHeader(WebhookSocialPublisher::SIGNATURE_HEADER));
    }

    /** @return iterable<string, array{string|Throwable|null, int, string}> */
    public static function unreadableSecrets(): iterable
    {
        yield 'access denied' => [new AccessDeniedException('Access denied to secret "nr_repurpose_webhook": insufficient permissions'), 1790410007, 'The webhook signing secret could not be read from nr-vault'];
        yield 'not found' => [null, 1790410008, 'The webhook signing secret was not found in nr-vault'];
        yield 'empty' => ['', 1790410008, 'The webhook signing secret was not found in nr-vault'];
    }

    #[DataProvider('unreadableSecrets')]
    public function testAnUnreadableSecretIsARefusalWithoutSending(string|Throwable|null $secret, int $code, string $message): void
    {
        try {
            $this->publisher(
                ['socialWebhookUrl' => 'https://hooks.example.com/social', 'socialWebhookSecretIdentifier' => 'nr_repurpose_webhook'],
                vault: ['nr_repurpose_webhook' => $secret],
            )->publish($this->post());
            self::fail('Expected a refusal');
        } catch (SocialPublishException $e) {
            self::assertSame($code, $e->getCode());
            self::assertSame($message, $e->getMessage());
        }

        self::assertSame(1, $this->http->unconsumed());
    }

    /** The secret no longer lives in the system configuration; a value left there stops publishing. */
    public function testASecretLeftInTheExtensionConfigurationStopsPublishing(): void
    {
        try {
            $this->publisher(['socialWebhookUrl' => 'https://hooks.example.com/social', 'socialWebhookSecret' => 's3cret'])->publish($this->post());
            self::fail('Expected a refusal');
        } catch (SocialPublishException $e) {
            self::assertSame(1790410006, $e->getCode());
            self::assertStringNotContainsString('s3cret', $e->getMessage());
        }

        self::assertSame(1, $this->http->unconsumed());
    }

    public function testWithoutASecretNoSignatureIsSent(): void
    {
        $this->publisher(['socialWebhookUrl' => 'https://hooks.example.com/social'])->publish($this->post());

        self::assertFalse($this->sentRequest()->hasHeader(WebhookSocialPublisher::SIGNATURE_HEADER));
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
        $unreachable = new ConnectException(
            'cURL error 7: Failed to connect to hooks.example.com port 443 (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://hooks.example.com/x?token=s3cr3t-t0ken',
            new Request('POST', 'https://hooks.example.com/x?token=s3cr3t-t0ken'),
        );

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

        self::assertSame(1, $this->http->unconsumed());
    }

    /** DuePostPublisher catches only SocialPublishException; anything else would leave the post claimed. */
    public function testAPostThatIsNotValidUtf8IsARefusalWithoutSending(): void
    {
        $post = new SocialPost(12, 3, 'linkedin', "Revenue \xB1 grew", 1_790_000_000, 'https://example.com/report', []);

        try {
            $this->publisher(['socialWebhookUrl' => 'https://hooks.example.com/x'])->publish($post);
            self::fail('Expected a refusal');
        } catch (SocialPublishException $e) {
            self::assertSame(1790410005, $e->getCode());
            self::assertSame('The post could not be encoded as JSON', $e->getMessage());
        }

        self::assertSame(1, $this->http->unconsumed());
    }

    public function testThePostIsSentToTheCheckedAddressWithATimeoutAndWithoutFollowingRedirects(): void
    {
        $this->publisher(['socialWebhookUrl' => 'https://hooks.example.com/social'])->publish($this->post());

        $options = $this->http->handler->getLastOptions();
        self::assertSame(WebhookSocialPublisher::TIMEOUT_SECONDS, $options[RequestOptions::TIMEOUT]);
        self::assertGreaterThan(0, $options[RequestOptions::CONNECT_TIMEOUT]);
        self::assertFalse($options[RequestOptions::ALLOW_REDIRECTS]);
        self::assertSame([CURLOPT_RESOLVE => ['hooks.example.com:443:93.184.215.14']], $options['curl'] ?? null);
    }

    /** Only the status counts: an accepted post with a large answer is published, not failed. */
    public function testALargeAnswerToAnAcceptedPostIsIgnored(): void
    {
        $answer = str_repeat('x', 5 * 1024 * 1024);

        $this->publisher(
            ['socialWebhookUrl' => 'https://hooks.example.com/social'],
            new Response(200, ['Content-Length' => (string) strlen($answer)], $answer),
        )->publish($this->post());

        self::assertSame(0, $this->http->unconsumed());
        self::assertSame([], $this->logger->records);
    }

    public function testARedirectIsNotFollowedAndCountsAsARefusal(): void
    {
        $this->expectException(SocialPublishException::class);
        $this->expectExceptionMessage('Webhook answered HTTP 302');

        $this->publisher(['socialWebhookUrl' => 'https://hooks.example.com/x'], new Response(302, ['Location' => 'http://169.254.169.254/']))->publish($this->post());
    }

    /** @return iterable<string, array{string}> */
    public static function refusedUrls(): iterable
    {
        yield 'loopback name' => ['http://localhost:8080/x?token=s3cr3t-t0ken'];
        yield 'metadata address' => ['http://169.254.169.254/latest/?token=s3cr3t-t0ken'];
        yield 'private network' => ['https://10.0.0.5/hook?token=s3cr3t-t0ken'];
        yield 'host does not resolve' => ['https://unknown.example/hook?token=s3cr3t-t0ken'];
    }

    /**
     * Only a host on the public internet receives the post; the refusal is a fixed text
     * without the URL, and nothing is sent.
     */
    #[DataProvider('refusedUrls')]
    public function testAWebhookOutsideThePublicInternetIsRefusedBeforeSending(string $url): void
    {
        $guard = new RemoteSourceGuard(new StaticHostResolver(['localhost' => ['127.0.0.1']]));

        try {
            $this->publisher(['socialWebhookUrl' => $url], guard: $guard)->publish($this->post());
            self::fail('Expected a refusal');
        } catch (SocialPublishException $e) {
            self::assertSame(1790410004, $e->getCode());
            self::assertSame('Webhook URL refused: only http and https to a host on the public internet are allowed', $e->getMessage());
        }

        self::assertSame(1, $this->http->unconsumed());
        self::assertStringNotContainsString('s3cr3t-t0ken', (string) json_encode($this->logger->records[0]['context']['url'] ?? ''));
    }
}
