<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Controller;

use Netresearch\NrRepurpose\Controller\JobController;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Functional\Controller\Fixtures\RecordingLogWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;
use TYPO3\CMS\Extbase\Service\ExtensionService;

/**
 * Renders the module actions through the real module stack: the new-job form's
 * script reaches the browser in a form the backend Content Security Policy
 * lets run (an ES module from the import map, not an inline <script> without
 * a nonce, which the browser refuses, leaving the double-submit guard dead),
 * the module stylesheet is linked, and the job list keeps a long source URL
 * on one line.
 */
#[CoversClass(JobController::class)]
final class JobControllerTest extends AbstractFunctionalTestCase
{
    private const MODULE_SPECIFIER = '@netresearch/nr-repurpose/job-new.js';

    private const ADMIN = 1;

    /** A non-admin without the approve permission. */
    private const EDITOR = 2;

    /** A non-admin whose group grants nrrepurpose:approve_artifacts. */
    private const REVIEWER = 3;

    /** @var array<string, mixed> */
    protected array $configurationToUseInTestInstance = [
        'LOG' => [
            'Netresearch' => [
                'NrRepurpose' => [
                    'Controller' => [
                        'writerConfiguration' => [
                            LogLevel::WARNING => [RecordingLogWriter::class => []],
                        ],
                    ],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/BeUsers.csv');
        RecordingLogWriter::$records = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        RecordingLogWriter::$records = [];
        parent::tearDown();
    }

    #[Test]
    public function newActionRendersNoInlineScriptWithoutNonce(): void
    {
        $body = $this->renderNewAction();

        self::assertStringNotContainsString("form.addEventListener('submit'", $body);

        self::assertGreaterThan(0, preg_match_all('/<script\b[^>]*>/i', $body, $matches));
        foreach ($matches[0] as $tag) {
            self::assertMatchesRegularExpression(
                '/\s(?:src|nonce)=/i',
                $tag,
                'Every script element must carry a src or a nonce, or the backend CSP blocks it: ' . $tag,
            );
        }
    }

    #[Test]
    public function newActionLoadsTheSubmitFeedbackModule(): void
    {
        $body = $this->renderNewAction();

        // The import map alone proves nothing here: ModuleTemplate includes every
        // registered import, loaded or not. A module the page actually loads is
        // rendered by JavaScriptRenderer as <script type="module" src="…">, with the
        // URL resolved through Configuration/JavaScriptModules.php (an unresolvable
        // specifier throws instead of rendering). So this tag exists only if
        // newAction() loaded the module AND the prefix is registered.
        self::assertMatchesRegularExpression(
            '#<script type="module" async="async" src="[^"]*nr_repurpose/Resources/Public/JavaScript/job-new\.js(?:\?[^"]*)?"#',
            $body,
            'The new-job page must load ' . self::MODULE_SPECIFIER . ' as an ES module.',
        );
        self::assertFileExists(GeneralUtility::getFileAbsFileName('EXT:nr_repurpose/Resources/Public/JavaScript/job-new.js'));
    }

    #[Test]
    public function newActionRendersAnEmptyStatusRegionForTheSubmitState(): void
    {
        $body = $this->renderNewAction();

        // job-new.js writes the submitting label into this region; a live region is
        // announced only when it is already in the page before its text changes.
        self::assertMatchesRegularExpression(
            '#<span class="visually-hidden" role="status" id="nrrepurpose-submit-status"></span>#',
            $body,
            'The new-job page must render an empty role="status" region for the submit state.',
        );
    }

    /** @return array<string, array{0: string}> */
    public static function renderedActions(): array
    {
        return ['list' => ['list'], 'new' => ['new'], 'plan' => ['plan']];
    }

    #[Test]
    #[DataProvider('renderedActions')]
    public function everyActionLoadsTheModuleStylesheet(string $action): void
    {
        self::assertMatchesRegularExpression(
            '#<link rel="stylesheet" href="[^"]*nr_repurpose/Resources/Public/Css/backend\.css(?:\?[^"]*)?"#',
            $this->renderAction($action),
        );
        self::assertFileExists(GeneralUtility::getFileAbsFileName('EXT:nr_repurpose/Resources/Public/Css/backend.css'));
    }

    #[Test]
    public function listActionCutsALongSourceToOneLineAndKeepsTheFullValue(): void
    {
        // The demo's job 2: a tracking URL of about 400 characters without a break opportunity.
        $url = 'https://www.example.com/de-de/explorer/paris/?utm_source=google&utm_medium=cpc&gclid=' . str_repeat('Cj0KCQjw', 45);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_job')
            ->insert('tx_nrrepurpose_domain_model_job', ['pid' => 0, 'source_type' => 'url', 'source_value' => $url, 'status' => 'failed']);

        $body    = $this->renderAction('list');
        $escaped = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5);

        self::assertStringContainsString('<table class="table table-striped table-hover" aria-labelledby="nrrepurpose-list-title">', $body);
        self::assertStringContainsString('<td class="col-responsive" title="' . $escaped . '">' . $escaped . '</td>', $body);
        self::assertMatchesRegularExpression('#<td class="col-control">\s*<a [^>]*class="btn btn-sm btn-default"#', $body);
    }

    #[Test]
    public function listActionDrawsANamedProgressBar(): void
    {
        $this->insertJob('https://example.com/report', 'queued');

        $body = $this->renderAction('list');

        // Own progressbar (core v14 has no .progress CSS; the core element is @internal and unnamed):
        // role, value range and name on one element, the fill width is the value, the percentage visible.
        self::assertMatchesRegularExpression(
            '~<div class="nrrepurpose-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"\s+aria-label="Progress of job #1">\s*'
            . '<div class="nrrepurpose-progress-track"><div class="nrrepurpose-progress-fill" style="width: 0%;"></div></div>\s*'
            . '<span class="nrrepurpose-progress-value">0%</span>~',
            $body,
        );
        self::assertStringNotContainsString('typo3-backend-progress-bar', $body);
    }

    #[Test]
    public function showActionPutsAFailedArtifactIntoTheCoreErrorBoxAndBreaksLongValues(): void
    {
        $url = 'https://www.example.com/?gclid=' . str_repeat('Cj0KCQjw', 45);
        $job = $this->insertJob($url, 'partially_done');
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->insert('tx_nrrepurpose_domain_model_artifact', ['pid' => 0, 'job' => $job, 'type' => 'podcast', 'status' => 'failed', 'error_message' => 'TTS refused ' . $url]);

        $body    = $this->renderAction('show', ['job' => $job]);
        $escaped = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5);

        self::assertStringContainsString('<dd class="col-sm-10 text-break">' . $escaped . '</dd>', $body);
        self::assertStringContainsString('<div class="card-body text-break">', $body);
        self::assertMatchesRegularExpression('#class="[^"]*\bcallout-danger\b.*?<span class="text-break">TTS refused ' . preg_quote($escaped, '#') . '</span>#s', $body);
    }

    #[Test]
    public function showActionReloadsARunningJobWithANonceScript(): void
    {
        $job = $this->insertJob('https://example.com/report', 'queued');

        // The inline reload needs the nonce, or the backend CSP blocks it. Whether the template asks
        // for it with csp="true" rather than the deprecated useNonce is checked on the template
        // source (BackendViewMarkupTest): Fluid compiles a template once per process, so a
        // deprecation raised while parsing may never reach this test.
        self::assertMatchesRegularExpression(
            '#<script nonce="[^"]+">setTimeout\(\(\) => window\.location\.reload\(\), 5000\);</script>#',
            $this->renderAction('show', ['job' => $job]),
        );
    }

    /** @return array<string, array{0: string}> */
    public static function modulePages(): array
    {
        return ['list' => ['list'], 'new' => ['new'], 'show' => ['show'], 'plan' => ['plan']];
    }

    /**
     * Every page the module renders, filled like the demo (a queued, non-terminal job with a failed artifact,
     * a scheduled post): each <script> carries a src or a nonce, since the backend CSP silently
     * blocks any other; and the module body has no <style> element and no style attribute except
     * the progress fill width. The backend CSP allows inline styles, so either would override
     * backend.css unseen by the stylesheet tests.
     */
    #[Test]
    #[DataProvider('modulePages')]
    public function modulePagesRenderNoRawScriptAndNoInlineStyle(string $action): void
    {
        $job = $this->insertJob('https://example.com/report', 'queued');
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->insert('tx_nrrepurpose_domain_model_artifact', ['pid' => 0, 'job' => $job, 'type' => 'podcast', 'status' => 'failed', 'error_message' => 'TTS refused']);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->insert('tx_nrrepurpose_domain_model_artifact', [
                'pid'           => 0, 'job' => $job, 'type' => 'social_post', 'variant' => 'linkedin', 'status' => 'done', 'script_text' => 'Post',
                'review_status' => 'approved', 'publish_status' => 'scheduled', 'publish_at' => 1790000000,
            ]);

        $body = $this->renderAction($action, $action === 'show' ? ['job' => $job] : []);

        self::assertGreaterThan(0, preg_match_all('/<script\b[^>]*>/i', $body, $scripts));
        foreach ($scripts[0] as $tag) {
            self::assertMatchesRegularExpression('/\s(?:src|nonce)=/i', $tag, $action . ': ' . $tag);
        }

        $start = strpos($body, '<div class="module-body t3js-module-body">');
        self::assertIsInt($start, $action . ': module body not found');
        $moduleBody = substr($body, $start);
        self::assertSame(0, preg_match('/<style\b/i', $moduleBody), $action . ': <style> element in the module body');
        preg_match_all('/<[^>]*\sstyle\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)[^>]*>/i', $moduleBody, $styled);
        foreach ($styled[0] as $tag) {
            self::assertMatchesRegularExpression('/^<div class="nrrepurpose-progress-fill" style="width: \d+%;">$/', $tag, $action . ': inline style');
        }
    }

    #[Test]
    public function planActionCutsTheSourceOfAPostToOneLine(): void
    {
        $url = 'https://www.example.com/?gclid=' . str_repeat('Cj0KCQjw', 45);
        $job = $this->insertJob($url, 'done');
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->insert('tx_nrrepurpose_domain_model_artifact', [
                'pid'           => 0, 'job' => $job, 'type' => 'social_post', 'variant' => 'linkedin', 'status' => 'done', 'script_text' => 'Post',
                'review_status' => 'approved', 'publish_status' => 'failed', 'publish_at' => 1790000000, 'publish_error' => 'HTTP 500',
            ]);

        $body    = $this->renderAction('plan');
        $escaped = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5);

        self::assertStringContainsString('<table class="table table-striped table-hover" data-role="plan" aria-labelledby="nrrepurpose-plan-title">', $body);
        self::assertStringContainsString('<td class="col-responsive" title="' . $escaped . '">', $body);
        self::assertStringContainsString('<span class="small text-break">HTTP 500</span>', $body);
    }

    #[Test]
    public function aRefusedReviewIsLoggedWithUserArtifactAndActionAndChangesNothing(): void
    {
        $job      = $this->insertJob('https://example.com/report', 'done');
        $artifact = $this->insertArtifact($job, ['type' => 'podcast']);

        $response = $this->dispatch('review', ['artifact' => $artifact, 'job' => $job, 'decision' => 'approved'], self::EDITOR, 'POST');

        $this->assertRedirectsToJob($response, $job);
        self::assertSame(
            [['You may not approve artifacts. An administrator grants "Approve artifacts" in the backend group.', ContextualFeedbackSeverity::ERROR]],
            $this->flashMessages(),
        );
        self::assertSame(['review_status' => '', 'reviewed_by' => 0, 'reviewed_at' => 0, 'publish_status' => '', 'publish_at' => 0], $this->reviewState($artifact));

        self::assertCount(1, RecordingLogWriter::$records);
        $record = RecordingLogWriter::$records[0];
        self::assertSame(LogLevel::WARNING, $record->getLevel());
        self::assertSame('Netresearch.NrRepurpose.Controller.JobController', $record->getComponent());
        self::assertSame(['action' => 'review', 'backendUser' => self::EDITOR, 'artifact' => $artifact, 'job' => $job], $record->getData());
    }

    /** @return array<string, array{0: string, 1: array<string, int|string>}> */
    public static function refusableSteps(): array
    {
        return [
            'schedule'   => ['schedule', ['publishAt' => '2026-10-01T09:30']],
            'unschedule' => ['unschedule', []],
        ];
    }

    /** @param array<string, int|string> $extra */
    #[Test]
    #[DataProvider('refusableSteps')]
    public function aRefusedSchedulingStepIsLoggedAndChangesNothing(string $action, array $extra): void
    {
        $job      = $this->insertJob('https://example.com/report', 'done');
        $artifact = $this->insertArtifact($job, ['type' => 'social_post', 'variant' => 'linkedin', 'review_status' => 'approved', 'publish_status' => 'scheduled', 'publish_at' => 1790000000]);

        $response = $this->dispatch($action, ['artifact' => $artifact, 'job' => $job] + $extra, self::EDITOR, 'POST');

        $this->assertRedirectsToJob($response, $job);
        self::assertSame(ContextualFeedbackSeverity::ERROR, $this->flashMessages()[0][1] ?? null);
        self::assertSame(['review_status' => 'approved', 'reviewed_by' => 0, 'reviewed_at' => 0, 'publish_status' => 'scheduled', 'publish_at' => 1790000000], $this->reviewState($artifact));
        self::assertCount(1, RecordingLogWriter::$records);
        self::assertSame(['action' => $action, 'backendUser' => self::EDITOR, 'artifact' => $artifact, 'job' => $job], RecordingLogWriter::$records[0]->getData());
    }

    #[Test]
    public function showingTheResultViewToAUserWithoutThePermissionLogsNoRefusal(): void
    {
        // showAction asks the same permission for canReview; not being offered the buttons is no refusal.
        $job = $this->insertJob('https://example.com/report', 'done');
        $this->insertArtifact($job, ['type' => 'podcast']);

        self::assertSame(200, $this->dispatch('show', ['job' => $job], self::EDITOR)->getStatusCode());
        self::assertSame([], RecordingLogWriter::$records);
    }

    private function insertJob(string $url, string $status): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_job');
        $connection->insert('tx_nrrepurpose_domain_model_job', ['pid' => 0, 'source_type' => 'url', 'source_value' => $url, 'status' => $status]);

        return (int) $connection->lastInsertId();
    }

    private function renderNewAction(): string
    {
        return $this->renderAction('new');
    }

    /** @param array<string, int|string> $arguments */
    private function renderAction(string $action, array $arguments = []): string
    {
        $response = $this->dispatch($action, $arguments);
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /** @param array<string, int|string> $arguments */
    private function dispatch(string $action, array $arguments = [], int $userUid = self::ADMIN, string $method = 'GET'): ResponseInterface
    {
        $backendUser     = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $request = $this->createBackendRequest($action, $arguments, $method);
        // Extbase reads its configuration while the controller is built, so the
        // ConfigurationManager needs the request before the container hands it out.
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);

        $controller = $this->get(JobController::class);
        self::assertInstanceOf(JobController::class, $controller);

        return $controller->processRequest($request);
    }

    /** @param array<string, int|string> $fields */
    private function insertArtifact(int $job, array $fields): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_artifact');
        $connection->insert('tx_nrrepurpose_domain_model_artifact', $fields + ['pid' => 0, 'job' => $job, 'status' => 'done']);

        return (int) $connection->lastInsertId();
    }

    /** @return array{review_status: string, reviewed_by: int, reviewed_at: int, publish_status: string, publish_at: int} */
    private function reviewState(int $artifact): array
    {
        $row = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrrepurpose_domain_model_artifact')
            ->select(['review_status', 'reviewed_by', 'reviewed_at', 'publish_status', 'publish_at'], 'tx_nrrepurpose_domain_model_artifact', ['uid' => $artifact])
            ->fetchAssociative();
        self::assertIsArray($row);

        return [
            'review_status'  => (string) $row['review_status'],
            'reviewed_by'    => (int) $row['reviewed_by'],
            'reviewed_at'    => (int) $row['reviewed_at'],
            'publish_status' => (string) $row['publish_status'],
            'publish_at'     => (int) $row['publish_at'],
        ];
    }

    /**
     * The flash messages the action queued for the module, as [text, severity].
     *
     * @return list<array{0: string, 1: ContextualFeedbackSeverity}>
     */
    private function flashMessages(): array
    {
        $identifier = 'extbase.flashmessages.' . $this->get(ExtensionService::class)->getPluginNamespace('NrRepurpose', 'web_nrrepurpose');

        return array_map(
            static fn (FlashMessage $message): array => [$message->getMessage(), $message->getSeverity()],
            $this->get(FlashMessageService::class)->getMessageQueueByIdentifier($identifier)->getAllMessages(),
        );
    }

    /** A 303 back to the job's result view, the route Extbase builds for action "show". */
    private function assertRedirectsToJob(ResponseInterface $response, int $job): void
    {
        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringEndsWith('/module/web/nr-repurpose/Job/show', (string) parse_url($location, PHP_URL_PATH));
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame((string) $job, $query['job'] ?? null);
    }

    /** @param array<string, int|string> $arguments */
    private function createBackendRequest(string $action, array $arguments = [], string $method = 'GET'): ExtbaseRequest
    {
        $extbaseParameters = new ExtbaseRequestParameters(JobController::class);
        $extbaseParameters->setPluginName('web_nrrepurpose');
        $extbaseParameters->setControllerExtensionName('NrRepurpose');
        $extbaseParameters->setControllerName('Job');
        $extbaseParameters->setControllerActionName($action);
        $extbaseParameters->setFormat('html');
        foreach ($arguments as $name => $value) {
            $extbaseParameters->setArgument($name, $value);
        }

        $route = $this->get(Router::class)->getRoute('web_nrrepurpose');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/module/web/nr-repurpose', $method))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $route)
            ->withAttribute('module', $route->getOption('module'))
            ->withAttribute('nonce', new ConsumableNonce())
            ->withAttribute('extbase', $extbaseParameters);
        $serverRequest            = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        return new ExtbaseRequest($serverRequest);
    }
}
