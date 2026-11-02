<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Testing\FakeBudgetService;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Domain\ValueObject\JobSnapshot;
use Netresearch\NrRepurpose\Domain\ValueObject\SourceDocument;
use Netresearch\NrRepurpose\Generator\ExecutiveSummaryGenerator;
use Netresearch\NrRepurpose\Generator\FaqGenerator;
use Netresearch\NrRepurpose\Generator\NewsletterGenerator;
use Netresearch\NrRepurpose\Generator\SocialPostGenerator;
use Netresearch\NrRepurpose\Generator\Support\TextLabels;
use Netresearch\NrRepurpose\Generator\Support\TextLimiter;
use Netresearch\NrRepurpose\Ingestion\SourceIngestionServiceInterface;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Pipeline\PromptSnippetResolver;
use Netresearch\NrRepurpose\Provenance\AiLabelSettingsFactory;
use Netresearch\NrRepurpose\Service\CapabilityGrantResolver;
use Netresearch\NrRepurpose\Service\ConfiguredCompletionService;
use Netresearch\NrRepurpose\Service\GenerationOrchestrator;
use Netresearch\NrRepurpose\Service\JobLlmOptions;
use Netresearch\NrRepurpose\Service\JobSubmissionService;
use Netresearch\NrRepurpose\Source\JobSourceAssigner;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Unit\Fixture\FakeTextCompletion;
use Netresearch\NrRepurpose\Understanding\DocumentAnalyzer;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * A page job keeps the site of its page through the queue, and every text call of its run names
 * that site, so nr-llm runs it on the configuration the site names in `nrllm.configuration`
 * (ADR-012, nr-llm ADR-244).
 *
 * Real: the page tree, the site and its settings, the form's source assignment, the submission,
 * the job row, the orchestrator, the analyzer, the text generators, ConfiguredCompletionService
 * and nr-llm's ConfigurationResolver. Recorded: the text completion the generators call, and
 * nr-llm's completion service behind ConfiguredCompletionService.
 */
final class JobSiteTest extends AbstractFunctionalTestCase
{
    private const int EDITOR = 2;

    private const int PAGE = 10;

    private const string SITE = 'main';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Source/Fixtures/SourcePages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Source/Fixtures/SourceUsers.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SiteLlmConfigurations.csv');
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->createNewBasicSite(self::SITE, 1, 'https://www.example.org/', ['netresearch/nr-llm']);
        $siteWriter->writeSettings(self::SITE, ['nrllm' => ['configuration' => 'main-writer']]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function aPageJobKeepsItsSiteAndEveryTextCallOfItsRunRunsOnTheSitesConfiguration(): void
    {
        $jobUid = $this->submit($this->pageJob());

        $row = $this->get(JobProcessingRepository::class)->findRow($jobUid) ?? [];
        self::assertSame(self::SITE, JobSnapshot::fromRow($row)->site);

        $completion = $this->runJob($jobUid);

        $calls = $this->recordedOptions($completion);
        // The analysis, then one structured completion per requested text format.
        self::assertCount(1, $completion->completeJsonCalls);
        self::assertCount(4, $completion->completeStructuredCalls);
        foreach ($calls as $operation => $options) {
            self::assertSame(self::SITE, $options->getSite(), $operation);
        }

        self::assertSame('main-writer', $this->configurationNrLlmRunsOn($calls['analyzeDocument']));
    }

    #[Test]
    public function aUrlJobNamesNoSiteAndKeepsTheInstanceDefault(): void
    {
        $job = new Job();
        $job->setSourceType('url');
        $job->setSourceValue('https://www.example.org/');
        $this->assigner()->assign($job, $this->editor(), 0, 0, 0, 'https://www.example.org');
        $jobUid = $this->submit($job);

        $completion = $this->runJob($jobUid);

        self::assertNotSame([], $this->recordedOptions($completion));
        foreach ($this->recordedOptions($completion) as $operation => $options) {
            self::assertNull($options->getSite(), $operation);
        }
    }

    /** Without the "nr_repurpose_text" record a text call takes the instance default, which the site replaces. */
    #[Test]
    public function withoutTheExtensionsConfigurationTheSitesConfigurationAnswers(): void
    {
        $inner = $this->createMock(CompletionServiceInterface::class);
        $inner->expects(self::never())->method('completeJsonForConfiguration');
        $seen = null;
        $inner->expects(self::once())->method('completeJson')->willReturnCallback(
            static function (string $prompt, ?ChatOptions $options) use (&$seen): array {
                $seen = $options;

                return ['ok' => true];
            },
        );

        $this->configured($inner)->completeJson('Prompt', $this->siteOptions());

        self::assertInstanceOf(ChatOptions::class, $seen);
        self::assertSame('main-writer', $this->configurationNrLlmRunsOn($seen));
    }

    /**
     * The extension's own configuration is an explicit choice and wins over the site (nr-llm
     * ADR-244); the call still names the site, for the term lists bound to it.
     */
    #[Test]
    public function theExtensionsConfigurationWinsAndTheCallStillNamesTheSite(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/RepurposeTextConfiguration.csv');
        $inner = $this->createMock(CompletionServiceInterface::class);
        $inner->expects(self::never())->method('completeJson');
        $seen = [];
        $inner->expects(self::once())->method('completeJsonForConfiguration')->willReturnCallback(
            static function (string $prompt, LlmConfiguration $configuration, ?ChatOptions $options) use (&$seen): array {
                $seen = [$configuration->getIdentifier(), $options?->getSite()];

                return ['ok' => true];
            },
        );

        $this->configured($inner)->completeJson('Prompt', $this->siteOptions());

        self::assertSame([ConfiguredCompletionService::CONFIGURATION, self::SITE], $seen);
    }

    private function pageJob(): Job
    {
        $job = new Job();
        $job->setSourceType('page');
        $this->assigner()->assign($job, $this->editor(), self::PAGE, 0, 0, 'https://www.example.org');

        return $job;
    }

    private function submit(Job $job): int
    {
        $job->setWantPodcast(false);
        $job->setWantSchaubild(false);
        $job->setWantStory(false);
        $job->setWantExecSummary(true);
        $job->setWantFaq(true);
        $job->setWantSocialPost(true);
        $job->setWantNewsletter(true);

        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return new Envelope($message);
            }
        };

        return (new JobSubmissionService(
            $this->get(JobRepository::class),
            $this->get(PersistenceManagerInterface::class),
            $bus,
            $this->get(JobProcessingRepository::class),
        ))->submit($job, self::EDITOR);
    }

    /** The worker's run of the job, with the source text given and the language model recorded. */
    private function runJob(int $jobUid): FakeTextCompletion
    {
        $completion             = new FakeTextCompletion();
        $completion->jsonResult = [
            'title'    => 'About', 'summary' => 'About us.', 'keyPoints' => ['We exist'], 'sections' => [],
            'audience' => 'Visitors', 'language' => 'en',
        ];
        $jobs   = $this->get(JobProcessingRepository::class);
        $budget = new FakeBudgetService();
        $logger = new NullLogger();
        $labels = new TextLabels($this->get(LanguageServiceFactory::class));

        $ingestion = new readonly class implements SourceIngestionServiceInterface {
            public function ingest(JobSnapshot $job): SourceDocument
            {
                return new SourceDocument(title: 'About', text: 'About us.', sourceLabel: 'About [10]', pageCount: 0, languageHint: 'en');
            }
        };

        (new GenerationOrchestrator(
            $jobs,
            $logger,
            $ingestion,
            new DocumentAnalyzer($completion, $logger),
            $this->get(PromptSnippetResolver::class),
            $this->get(TechnicalActorContextInterface::class),
            $this->get(ExtensionConfiguration::class),
            $this->get(CapabilityGrantResolver::class),
            $this->get(AiLabelSettingsFactory::class),
            [
                new ExecutiveSummaryGenerator($jobs, $budget, $logger, $completion),
                new FaqGenerator($jobs, $budget, $logger, $completion, $labels),
                new SocialPostGenerator($jobs, $budget, $logger, $completion, new TextLimiter()),
                new NewsletterGenerator($jobs, $budget, $logger, $completion, $labels),
            ],
        ))->process($jobUid);

        return $completion;
    }

    /**
     * Every recorded call's options, keyed by the operation it names.
     *
     * @return array<string, ChatOptions>
     */
    private function recordedOptions(FakeTextCompletion $completion): array
    {
        $options = [];
        foreach ([...$completion->completeJsonCalls, ...$completion->completeStructuredCalls, ...$completion->completeMarkdownCalls] as $call) {
            self::assertInstanceOf(ChatOptions::class, $call['options']);
            $options[(string) $call['options']->getCallerSourceOperation()] = $call['options'];
        }

        return $options;
    }

    /**
     * The configuration nr-llm's chat()/complete() run on for these options, resolved the way
     * LlmServiceManager resolves it for a call without a pinned provider.
     */
    private function configurationNrLlmRunsOn(ChatOptions $options): string
    {
        $configuration = $this->get(ConfigurationResolver::class)->resolveDefaultConfiguration(null, $options->getSite());

        return $configuration instanceof LlmConfiguration ? $configuration->getIdentifier() : '(instance default)';
    }

    private function configured(CompletionServiceInterface $inner): ConfiguredCompletionService
    {
        return new ConfiguredCompletionService($inner, $this->get(ConfigurationResolver::class), new NullLogger());
    }

    private function siteOptions(): ChatOptions
    {
        $row = $this->get(JobProcessingRepository::class)->findRow($this->submit($this->pageJob())) ?? [];

        return JobLlmOptions::forJob(new ChatOptions(), JobSnapshot::fromRow($row));
    }

    private function assigner(): JobSourceAssigner
    {
        return $this->get(JobSourceAssigner::class);
    }

    private function editor(): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser(self::EDITOR);
        self::assertInstanceOf(BackendUserAuthentication::class, $user);

        return $user;
    }
}
