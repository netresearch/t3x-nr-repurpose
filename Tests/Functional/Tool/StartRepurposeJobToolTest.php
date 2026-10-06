<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Tool;

use Netresearch\NrLlm\Service\Tool\ToolApprovalRule;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Persistence\JobProcessingRepository;
use Netresearch\NrRepurpose\Queue\Message\GenerateArtifactsMessage;
use Netresearch\NrRepurpose\Service\JobSubmissionService;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tool\StartRepurposeJobTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

final class StartRepurposeJobToolTest extends AbstractFunctionalTestCase
{
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

        return new StartRepurposeJobTool(new JobSubmissionService(
            $this->get(JobRepository::class),
            $this->get(PersistenceManagerInterface::class),
            $bus,
        ));
    }

    private function contextOf(int $beUserUid): ToolExecutionContext
    {
        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $user->setBeUserByUid($beUserUid);
        $user->fetchGroupData();

        return ToolExecutionContext::fromBackendUser($user);
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
