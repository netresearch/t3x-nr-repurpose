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

/**
 * The way out a stalled job offers when it cannot be started again (#238): finished results
 * block the retry, a live run on the job only postpones it. Each case says its own reason.
 */
final class FailureNoticeRenderingTest extends AbstractFunctionalTestCase
{
    private const string BLOCKED = 'This job holds finished results.';

    private const string RUNNING = 'A run is still working on this job.';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    public function testAStalledJobThatALiveRunHoldsSaysSoInsteadOfNamingFinishedResults(): void
    {
        $html = $this->render($this->stalledJob(liveClaim: true, doneArtifact: false));

        self::assertStringContainsString(self::RUNNING, $html);
        self::assertStringNotContainsString(self::BLOCKED, $html);
    }

    public function testAStalledJobWithFinishedResultsNamesThem(): void
    {
        $html = $this->render($this->stalledJob(liveClaim: false, doneArtifact: true));

        self::assertStringContainsString(self::BLOCKED, $html);
        self::assertStringNotContainsString(self::RUNNING, $html);
    }

    private function stalledJob(bool $liveClaim, bool $doneArtifact): Job
    {
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('tx_nrrepurpose_domain_model_job')->insert('tx_nrrepurpose_domain_model_job', [
            'pid'            => 0,
            'source_type'    => 'url',
            'source_value'   => 'https://example.com/',
            'status'         => 'generating',
            'tstamp'         => time() - Job::STALLED_AFTER - 60,
            'run_token'      => $liveClaim ? 'live' : '',
            'run_claimed_at' => $liveClaim ? time() : 0,
        ]);
        $uid = (int) $pool->getConnectionForTable('tx_nrrepurpose_domain_model_job')->lastInsertId();
        if ($doneArtifact) {
            $pool->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')->insert('tx_nrrepurpose_domain_model_artifact', [
                'pid' => 0, 'job' => $uid, 'type' => 'faq', 'variant' => 'default', 'status' => 'done',
            ]);
        }

        $job = $this->get(JobRepository::class)->findByUid($uid);
        self::assertInstanceOf(Job::class, $job);
        self::assertFalse($job->isRetryable(), 'neither case offers the button');

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
