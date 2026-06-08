<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Ingestion;

use GuzzleHttp\Psr7\HttpFactory;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Ingestion\PdfFileResolver;
use Netresearch\NrRepurpose\Ingestion\PdfLayoutExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfTextExtractor;
use Netresearch\NrRepurpose\Ingestion\PdfVisionExtractor;
use Netresearch\NrRepurpose\Ingestion\Poppler\SymfonyProcessPopplerRunner;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionService;
use Netresearch\NrRepurpose\Ingestion\WebPageFetcher;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class SourceIngestionServiceTest extends AbstractFunctionalTestCase
{
    private function fixturePdf(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Pdf/sample-text.pdf';
    }

    private function htmlClient(): ClientInterface
    {
        $html = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Web/article.html');

        return new class($html) implements ClientInterface {
            public function __construct(private readonly string $html) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write($this->html);
                $response->getBody()->rewind();

                return $response;
            }
        };
    }

    /** Fails loudly if the auto dispatcher escalates a dense text PDF to Vision. */
    private function explodingVision(): PdfVisionExtractor
    {
        return new class extends PdfVisionExtractor {
            public function __construct() {}

            public function ocrPage(string $absPdfPath, int $page, int $beUser, int $dpi = 200): string
            {
                throw new \LogicException('Vision must not be called for a text PDF in auto mode');
            }
        };
    }

    private function service(ClientInterface $client, PdfVisionExtractor $vision): SourceIngestionService
    {
        $factory = new HttpFactory();
        $runner = new SymfonyProcessPopplerRunner();

        return new SourceIngestionService(
            new WebPageFetcher($client, $factory),
            new PdfFileResolver($this->get(ResourceFactory::class), $client, $factory),
            new PdfTextExtractor(),
            $vision,
            new PdfLayoutExtractor($runner),
        );
    }

    public function testIngestsStaticHtmlIntoSourceDocument(): void
    {
        $doc = $this->service($this->htmlClient(), $this->explodingVision())
            ->ingest(['uid' => 1, 'source_type' => 'url', 'source_value' => 'https://example.com/q1', 'be_user' => 0]);

        self::assertInstanceOf(SourceDocument::class, $doc);
        self::assertSame('Quarterly Results 2026', $doc->title);
        self::assertStringContainsString('Revenue grew by 12 percent', $doc->text);
        self::assertSame(0, $doc->pageCount);
        self::assertSame('static', $doc->meta['fetchedVia']);
    }

    public function testIngestsRealTextPdfFalSourceViaAutoTier1(): void
    {
        $storage = $this->get(StorageRepository::class)->getDefaultStorage();
        self::assertNotNull($storage);
        $folder = $storage->hasFolder('repurpose') ? $storage->getFolder('repurpose') : $storage->createFolder('repurpose');
        $file = $storage->createFile('ingest-test.pdf', $folder);
        $file->setContents((string) file_get_contents($this->fixturePdf()));

        $doc = $this->service($this->htmlClient(), $this->explodingVision())
            ->ingest([
                'uid' => 2, 'source_type' => 'pdf_fal', 'source_pdf' => $file->getUid(),
                'pdf_mode' => 'auto', 'be_user' => 0,
            ]);

        self::assertStringContainsString('Net revenue rose to 48 million euro', $doc->text);
        self::assertSame(1, $doc->pageCount);
        self::assertContains('text', $doc->meta['tiersUsed']);
        self::assertNotContains('vision', $doc->meta['tiersUsed']);
    }
}
