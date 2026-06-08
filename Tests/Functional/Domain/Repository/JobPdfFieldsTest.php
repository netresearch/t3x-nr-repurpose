<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Domain\Repository;

use Netresearch\NrRepurpose\Domain\Enum\PdfMode;
use Netresearch\NrRepurpose\Domain\Enum\SourceType;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

final class JobPdfFieldsTest extends AbstractFunctionalTestCase
{
    public function testPdfModeRoundTripsAndSourceTypePersists(): void
    {
        $repo = $this->get(JobRepository::class);
        $pm = $this->get(PersistenceManagerInterface::class);

        $job = new Job();
        $job->setSourceTypeEnum(SourceType::PdfUrl);
        $job->setSourceValue('https://example.com/report.pdf');
        $job->setPdfModeEnum(PdfMode::Vision);
        $repo->add($job);
        $pm->persistAll();
        $pm->clearState();

        /** @var Job $loaded */
        $loaded = $repo->findByUid($job->getUid());
        self::assertSame(SourceType::PdfUrl, $loaded->getSourceTypeEnum());
        self::assertSame(PdfMode::Vision, $loaded->getPdfModeEnum());
    }

    public function testDefaultPdfModeIsAuto(): void
    {
        $job = new Job();
        self::assertSame(PdfMode::Auto, $job->getPdfModeEnum());
    }
}
