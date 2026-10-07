<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Ingestion;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Netresearch\NrRepurpose\Ingestion\IngestionException;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Ingestion\RemoteSourceGuard;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\JobSnapshots;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\QueuedHttpClient;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\StaticHostResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ResourceStorage;

final class PdfFileResolverTest extends TestCase
{
    private function resolver(QueuedHttpClient $http, ?RemoteSourceGuard $guard = null): PdfFileResolver
    {
        return new PdfFileResolver(
            $this->createStub(FileRepository::class),
            $http->client,
            new HttpFactory(),
            $guard ?? StaticHostResolver::publicGuard(),
        );
    }

    public function testRefusesAPdfUrlOnThePrivateNetworkBeforeSendingAnyRequest(): void
    {
        $http     = QueuedHttpClient::answering(200, '%PDF-1.7');
        $resolver = $this->resolver($http, new RemoteSourceGuard(new StaticHostResolver(['intranet.example' => ['10.0.0.8']])));

        try {
            $resolver->resolve(JobSnapshots::of(['source_type' => 'pdf_url', 'source_value' => 'https://intranet.example/report.pdf']));
            self::fail('A private address must be refused');
        } catch (IngestionException $e) {
            self::assertSame(1749379463, $e->getCode());
        }

        self::assertSame(1, $http->unconsumed());
    }

    /** guzzlehttp/psr7 2.9.0 cannot parse this literal; a newer release parses it and the address check refuses it. */
    public function testRefusesAnIpv4MappedLiteralBeforeSendingAnyRequest(): void
    {
        $http     = QueuedHttpClient::answering(200, '%PDF-1.7');
        $resolver = $this->resolver($http, new RemoteSourceGuard(new StaticHostResolver()));

        try {
            $resolver->resolve(JobSnapshots::of(['source_type' => 'pdf_url', 'source_value' => 'https://[::ffff:127.0.0.1]/report.pdf']));
            self::fail('An IPv4-mapped loopback literal must be refused');
        } catch (IngestionException $e) {
            self::assertContains($e->getCode(), [1749379463, 1749379468]);
        }

        self::assertSame(1, $http->unconsumed());
    }

    public function testDownloadsWithATimeoutAndWithoutFollowingRedirects(): void
    {
        $http = QueuedHttpClient::answering(200, '%PDF-1.7 body');

        $path = $this->resolver($http)->resolve(JobSnapshots::of(['source_type' => 'pdf_url', 'source_value' => 'https://example.com/r.pdf']));
        $body = (string) file_get_contents($path);
        unlink($path);

        self::assertSame('%PDF-1.7 body', $body);
        $options = $http->handler->getLastOptions();
        self::assertSame(PdfFileResolver::TIMEOUT_SECONDS, $options[RequestOptions::TIMEOUT]);
        self::assertFalse($options[RequestOptions::ALLOW_REDIRECTS]);
        self::assertFalse($options[RequestOptions::DECODE_CONTENT]);
        self::assertIsCallable($options[RequestOptions::PROGRESS]);
        // Connected to the address the guard checked (StaticHostResolver::publicGuard()'s).
        self::assertSame([CURLOPT_RESOLVE => ['example.com:443:93.184.215.14']], $options['curl'] ?? null);
    }

    public function testRefusesAPdfLargerThanTheLimit(): void
    {
        $http = new QueuedHttpClient(new Response(200, [], str_repeat('x', PdfFileResolver::MAX_BYTES + 1)));

        $this->expectException(IngestionException::class);
        $this->expectExceptionCode(1749379464);

        $this->resolver($http)->resolve(JobSnapshots::of(['source_type' => 'pdf_url', 'source_value' => 'https://example.com/huge.pdf']));
    }

    public function testAFailedWriteLeavesNoPartialDownloadBehind(): void
    {
        // What a full disk looks like: part of the bytes land, then the write fails.
        $resolver = new class (QueuedHttpClient::answering(200, '%PDF-1.7 body')->client) extends PdfFileResolver {
            public ?string $writtenTo = null;

            public function __construct(ClientInterface $client)
            {
                parent::__construct(
                    (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor(),
                    $client,
                    new HttpFactory(),
                    StaticHostResolver::publicGuard(),
                );
            }

            protected function writeFile(string $path, string $bytes): bool
            {
                $this->writtenTo = $path;
                file_put_contents($path, substr($bytes, 0, 4));

                return false;
            }
        };

        try {
            $resolver->resolve(JobSnapshots::of(['source_type' => 'pdf_url', 'source_value' => 'https://example.com/r.pdf']));
            self::fail('A failed write must fail the download');
        } catch (IngestionException $e) {
            self::assertSame(1749379448, $e->getCode());
        }

        self::assertNotNull($resolver->writtenTo);
        self::assertFileDoesNotExist($resolver->writtenTo);
    }

    public function testAnAttachedPdfOnTheLocalDriverIsReadInPlaceAndNeverReleased(): void
    {
        $dir = sys_get_temp_dir() . '/fileadmin_' . bin2hex(random_bytes(6));
        mkdir($dir);
        $original = $dir . '/report.pdf';
        file_put_contents($original, '%PDF local');

        try {
            $resolver = $this->resolverForFile($this->falFile('Local', $original));

            $path = $resolver->resolve(JobSnapshots::of(['uid' => 5, 'source_type' => 'pdf_fal', 'source_pdf' => 1]));
            $resolver->release($path);

            self::assertSame($original, $path);
            self::assertFileExists($original);
        } finally {
            unlink($original);
            rmdir($dir);
        }
    }

    public function testAnAttachedPdfOnAnotherDriverIsCopiedAndTheCopyReleased(): void
    {
        $transient = sys_get_temp_dir() . '/transient_' . bin2hex(random_bytes(6));
        mkdir($transient);
        $file = $this->falFile('FakeRemote', null, $transient);

        try {
            $resolver = $this->resolverForFile($file);

            $path = $resolver->resolve(JobSnapshots::of(['uid' => 5, 'source_type' => 'pdf_fal', 'source_pdf' => 1]));
            self::assertSame('%PDF remote', (string) file_get_contents($path));
            $resolver->release($path);

            self::assertFileDoesNotExist($path);
            // The driver's own processing copy (var/transient) was never made.
            self::assertSame([], array_values(array_diff((array) scandir($transient), ['.', '..'])));
        } finally {
            array_map(unlink(...), (array) glob($transient . '/*'));
            rmdir($transient);
        }
    }

    /** A resolver for which job 5 has $file attached through its source_pdf field. */
    private function resolverForFile(File $file): PdfFileResolver
    {
        $reference = $this->createStub(FileReference::class);
        $reference->method('getOriginalFile')->willReturn($file);

        $repository = $this->createStub(FileRepository::class);
        $repository->method('findByRelation')->willReturnCallback(
            static fn (string $table, string $field, int $uid): array => [$table, $field, $uid] === ['tx_nrrepurpose_domain_model_job', 'source_pdf', 5] ? [$reference] : [],
        );

        return new PdfFileResolver($repository, QueuedHttpClient::answering(200, '')->client, new HttpFactory(), StaticHostResolver::publicGuard());
    }

    /**
     * A sys_file on a storage with the given driver. getForLocalProcessing() behaves like
     * the drivers: the local one returns the stored file, any other copies it into a
     * transient directory and returns the copy.
     */
    private function falFile(string $driverType, ?string $localPath, string $transient = ''): File
    {
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getDriverType')->willReturn($driverType);

        $file = $this->createStub(File::class);
        $file->method('getStorage')->willReturn($storage);
        $file->method('getContents')->willReturn('%PDF remote');
        $file->method('getForLocalProcessing')->willReturnCallback(static function () use ($localPath, $transient): string {
            if ($localPath !== null) {
                return $localPath;
            }

            $copy = $transient . '/fal-tempfile-' . bin2hex(random_bytes(4)) . '.pdf';
            file_put_contents($copy, '%PDF remote');

            return $copy;
        });

        return $file;
    }
}
