<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Rendering;

use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/** A stalled job with finished results names them as what blocks a new run (#238). */
final class FailureNoticeRenderingTest extends AbstractFunctionalTestCase
{
    private const string BLOCKED = 'This job holds finished results.';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    public function testAStalledJobWithFinishedResultsNamesThem(): void
    {
        $html = $this->render($this->stalledJob(doneArtifact: true));

        self::assertStringContainsString(self::BLOCKED, $html);
    }

    private function stalledJob(bool $doneArtifact): Job
    {
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('tx_nrrepurpose_domain_model_job')->insert('tx_nrrepurpose_domain_model_job', [
            'pid'          => 0,
            'source_type'  => 'url',
            'source_value' => 'https://example.com/',
            'status'       => 'generating',
            'tstamp'       => time() - Job::STALLED_AFTER - 60,
        ]);
        $uid = (int) $pool->getConnectionForTable('tx_nrrepurpose_domain_model_job')->lastInsertId();
        if ($doneArtifact) {
            $pool->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')->insert('tx_nrrepurpose_domain_model_artifact', [
                'pid' => 0, 'job' => $uid, 'type' => 'faq', 'variant' => 'default', 'status' => 'done',
            ]);
        }

        $job = $this->get(JobRepository::class)->findByUid($uid);
        self::assertInstanceOf(Job::class, $job);
        self::assertFalse($job->isRetryable(), 'finished results block the button');

        return $job;
    }

    private function render(Job $job): string
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templatePathAndFilename: GeneralUtility::getFileAbsFileName('EXT:nr_repurpose/Resources/Private/Partials/Job/FailureNotice.html'),
        ));
        $view->assign('job', $job);

        return $view->render();
    }
}
