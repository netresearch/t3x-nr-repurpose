<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Service\Tool\ToolApprovalRule;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Netresearch\NrRepurpose\Service\JobSubmissionService;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tool\StartRepurposeJobTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

final class StartRepurposeJobToolTest extends AbstractFunctionalTestCase
{
    /** German label of the main module "content"; the test instance has no language pack. */
    protected array $configurationToUseInTestInstance = [
        'LANG' => [
            'resourceOverrides' => [
                'de' => [
                    'EXT:core/Resources/Private/Language/Modules/content.xlf' => ['EXT:nr_repurpose/Tests/Functional/Tool/Fixtures/de.content.xlf'],
                ],
            ],
        ],
    ];

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/ToolUsers.csv');
        $this->dispatched = [];
    }

    public function testTheToolIsRegisteredInTheNrLlmToolRegistryAndNeedsApproval(): void
    {
        $tool = $this->get(ToolRegistry::class)->get('start_repurpose_job');

        self::assertInstanceOf(StartRepurposeJobTool::class, $tool);
        self::assertTrue(ToolApprovalRule::requiresApproval($tool), 'a call starts spend-heavy generation, so a person must approve it');
        self::assertFalse($tool->requiresAdmin());
        self::assertFalse($tool->isEnabledByDefault(), 'a job spends provider money: an administrator switches the tool on');
        self::assertSame('nr_repurpose', $tool->getGroup());
    }

    public function testTheToolDeclaresEditorContentAsItsDataClass(): void
    {
        // What the result carries back into the run is the uid of the job row it
        // just created, the caller's own URL without query and fragment, and the
        // artifact names: an unpublished backend record, nothing world-readable
        // and nothing from the installation's configuration or internals.
        self::assertSame(ToolDataClass::EDITOR_CONTENT, $this->get(ToolDataClassResolver::class)->classFor('start_repurpose_job'));
    }

    public function testTheTrustZoneGateOffersTheToolToARunAgainstAnExternalGlobalProvider(): void
    {
        $this->get(ToolStateRepository::class)->setEnabled('start_repurpose_job', true);

        // A provider without a trust zone, as the demo's: it resolves to the
        // strictest zone, externalGlobal, whose ceiling is editorContent.
        $provider = new Provider();
        $provider->setTrustZone('');

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        $decision = $this->get(ToolCallPolicyInterface::class)->decide('start_repurpose_job', $configuration, $this->userOf(11));

        self::assertSame(
            ['allowed' => true, 'reason' => ToolDenialReason::NONE, 'observedOnly' => false, 'dataClass' => ToolDataClass::EDITOR_CONTENT, 'zone' => TrustZone::EXTERNAL_GLOBAL, 'ceiling' => ToolDataClass::EDITOR_CONTENT],
            ['allowed' => $decision->allowed, 'reason' => $decision->reason, 'observedOnly' => $decision->observedOnly, 'dataClass' => $decision->dataClass, 'zone' => $decision->zone, 'ceiling' => $decision->ceiling],
        );
    }

    public function testAnEditorWithModuleAccessStartsAJobOwnedByThemselves(): void
    {
        $result = $this->tool()->execute(
            ['source_url' => 'https://example.com/article', 'artifacts' => ['faq', 'exec_summary']],
            $this->contextOf(11),
        );

        self::assertFalse($result->isError, $result->content);

        $uid = $this->onlyJobUid();
        self::assertStringContainsString('#' . $uid, $result->content);

        $row = $this->get(JobProcessingRepository::class)->findRow($uid) ?? [];
        self::assertSame('https://example.com/article', $row['source_value']);
        self::assertSame('url', $row['source_type']);
        self::assertSame(11, (int) $row['be_user']);
        self::assertSame(
            ['faq' => 1, 'exec_summary' => 1, 'podcast' => 0, 'schaubild' => 0, 'story' => 0, 'social_post' => 0, 'newsletter' => 0, 'slide_deck' => 0, 'handout' => 0],
            [
                'faq'          => (int) $row['want_faq'],
                'exec_summary' => (int) $row['want_exec_summary'],
                'podcast'      => (int) $row['want_podcast'],
                'schaubild'    => (int) $row['want_schaubild'],
                'story'        => (int) $row['want_story'],
                'social_post'  => (int) $row['want_social_post'],
                'newsletter'   => (int) $row['want_newsletter'],
                'slide_deck'   => (int) $row['want_slide_deck'],
                'handout'      => (int) $row['want_handout'],
            ],
        );

        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(GenerateArtifactsMessage::class, $this->dispatched[0]);
    }

    public function testTheResultNamesTheModuleUnderItsParentInTheBackendMenu(): void
    {
        // TYPO3 14 registers the main module "content" (alias "web"), labelled
        // "Content"; the result named it "Web" before.
        $result = $this->tool()->execute(['source_url' => 'https://example.com/', 'artifacts' => ['faq']], $this->contextOf(11));

        self::assertFalse($result->isError, $result->content);
        self::assertStringEndsWith('progress and results are in the backend module Content > Repurpose.', $result->content);
    }

    public function testTheMenuPathIsInTheActingUsersBackendLanguage(): void
    {
        $result = $this->tool()->execute(['source_url' => 'https://example.com/', 'artifacts' => ['faq']], $this->contextOf(13));

        self::assertFalse($result->isError, $result->content);
        self::assertStringEndsWith('progress and results are in the backend module Inhalt > Repurpose.', $result->content);
    }

    public function testTheToolDescriptionNamesNoMenuPath(): void
    {
        // The description is the same for every user and language, so it names
        // the module only; the result carries the resolved path.
        $description = $this->tool()->getSpec()->description;

        self::assertStringContainsString('Repurpose backend module', $description);
        self::assertStringNotContainsString('Web >', $description);
    }

    public function testTheResultDoesNotRepeatQueryStringOrFragmentOfTheSourceUrl(): void
    {
        $result = $this->tool()->execute(
            ['source_url' => 'https://example.com/article?token=s3cret#part', 'artifacts' => ['faq']],
            $this->contextOf(11),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('https://example.com/article', $result->content);
        self::assertStringNotContainsString('s3cret', $result->content);
        $row = $this->get(JobProcessingRepository::class)->findRow($this->onlyJobUid()) ?? [];
        self::assertSame('https://example.com/article?token=s3cret#part', $row['source_value'], 'the job keeps the full URL for ingestion');
    }

    public function testAnAdministratorMayStartAPdfSourceJob(): void
    {
        $result = $this->tool()->execute(
            ['source_url' => 'https://example.com/report.pdf', 'source_type' => 'pdf_url', 'artifacts' => ['handout']],
            $this->contextOf(10),
        );

        self::assertFalse($result->isError, $result->content);
        $row = $this->get(JobProcessingRepository::class)->findRow($this->onlyJobUid()) ?? [];
        self::assertSame('pdf_url', $row['source_type']);
        self::assertSame(1, (int) $row['want_handout']);
    }

    public function testARunWithoutAnActingUserStartsNothing(): void
    {
        $result = $this->tool()->execute(
            ['source_url' => 'https://example.com/', 'artifacts' => ['faq']],
            ToolExecutionContext::none(),
        );

        self::assertTrue($result->isError);
        self::assertSame([], $this->dispatched);
        self::assertSame(0, $this->jobCount());
    }

    public function testAUserWithoutAccessToTheRepurposeModuleStartsNothing(): void
    {
        $result = $this->tool()->execute(
            ['source_url' => 'https://example.com/', 'artifacts' => ['faq']],
            $this->contextOf(12),
        );

        self::assertTrue($result->isError);
        self::assertSame([], $this->dispatched);
        self::assertSame(0, $this->jobCount());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function refusedArguments(): iterable
    {
        yield 'no source' => [['artifacts' => ['faq']]];
        yield 'not a url' => [['source_url' => 'not a url', 'artifacts' => ['faq']]];
        yield 'file scheme' => [['source_url' => 'file:///etc/passwd', 'artifacts' => ['faq']]];
        yield 'credentials in the url' => [['source_url' => 'https://user:secret@example.com/', 'artifacts' => ['faq']]];
        yield 'unknown source type' => [['source_url' => 'https://example.com/', 'source_type' => 'pdf_fal', 'artifacts' => ['faq']]];
        yield 'source type null' => [['source_url' => 'https://example.com/', 'source_type' => null, 'artifacts' => ['faq']]];
        yield 'source type not a string' => [['source_url' => 'https://example.com/', 'source_type' => 5, 'artifacts' => ['faq']]];
        yield 'no artifact' => [['source_url' => 'https://example.com/', 'artifacts' => []]];
        yield 'artifacts missing' => [['source_url' => 'https://example.com/']];
        yield 'unknown artifact' => [['source_url' => 'https://example.com/', 'artifacts' => ['faq', 'hologram']]];
        yield 'artifacts not a list' => [['source_url' => 'https://example.com/', 'artifacts' => 'faq']];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('refusedArguments')]
    public function testInvalidArgumentsAreRefusedBeforeAnythingIsPersisted(array $arguments): void
    {
        $result = $this->tool()->execute($arguments, $this->contextOf(11));

        self::assertTrue($result->isError);
        self::assertSame([], $this->dispatched);
        self::assertSame(0, $this->jobCount());
    }

    private function tool(): StartRepurposeJobTool
    {
        $dispatched = &$this->dispatched;
        $bus        = new class ($dispatched) implements MessageBusInterface {
            /** @param list<object> $sink */
            public function __construct(private array &$sink) {}

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sink[] = $message;

                return new Envelope($message);
            }
        };

        return new StartRepurposeJobTool(
            new JobSubmissionService(
                $this->get(JobRepository::class),
                $this->get(PersistenceManagerInterface::class),
                $bus,
            ),
            $this->get(ModuleProvider::class),
            $this->get(LanguageServiceFactory::class),
        );
    }

    private function contextOf(int $beUserUid): ToolExecutionContext
    {
        return ToolExecutionContext::fromBackendUser($this->userOf($beUserUid));
    }

    private function userOf(int $beUserUid): BackendUserAuthentication
    {
        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $user->setBeUserByUid($beUserUid);
        $user->fetchGroupData();

        return $user;
    }

    private function jobCount(): int
    {
        return $this->getConnectionPool()
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job')
            ->count('*', 'tx_nrrepurpose_domain_model_job', []);
    }

    private function onlyJobUid(): int
    {
        self::assertSame(1, $this->jobCount());

        return (int) $this->getConnectionPool()
            ->getConnectionForTable('tx_nrrepurpose_domain_model_job')
            ->select(['uid'], 'tx_nrrepurpose_domain_model_job')
            ->fetchOne();
    }
}
