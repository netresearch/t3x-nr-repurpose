<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Controller;

use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use Masterminds\HTML5;
use Netresearch\NrRepurpose\Controller\JobController;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrRepurpose\Tests\Functional\Controller\Fixtures\QueryCountingMiddleware;
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
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
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
    private const string MODULE_SPECIFIER = '@netresearch/nr-repurpose/job-new.js';

    private const ADMIN = 1;

    private const JOBS_PER_PAGE = 25;

    /** A non-admin without the approve permission. */
    private const EDITOR = 2;

    /** A non-admin whose group grants nrrepurpose:approve_artifacts. */
    private const REVIEWER = 3;

    /** @var array<string, mixed> */
    protected array $configurationToUseInTestInstance = [
        'DB' => [
            'Connections' => [
                'Default' => [
                    'driverMiddlewares' => [
                        'nrrepurpose-query-counter' => ['target' => QueryCountingMiddleware::class],
                    ],
                ],
            ],
        ],
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
        RecordingLogWriter::$records      = [];
        QueryCountingMiddleware::$queries = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        RecordingLogWriter::$records      = [];
        QueryCountingMiddleware::$queries = [];
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
        $job = $this->insertJob('https://example.com/report', 'queued');

        $body = $this->renderAction('list');

        // A native <progress> (core v14 has no .progress CSS; the core element is @internal and unnamed):
        // it carries role, value, range and a per-row name itself; the percentage beside it is for sight only.
        self::assertMatchesRegularExpression(
            '~<div class="nrrepurpose-progress">\s*'
            . '<progress class="nrrepurpose-progress-track" max="100" value="0"\s+aria-label="Progress of job #' . $job . '"></progress>\s*'
            . '<span class="nrrepurpose-progress-value" aria-hidden="true">0%</span>~',
            $body,
        );
        self::assertStringNotContainsString('typo3-backend-progress-bar', $body);
    }

    #[Test]
    public function listActionShowsNoPagerWhenAllJobsFitOnOnePage(): void
    {
        for ($i = 0; $i < self::JOBS_PER_PAGE; ++$i) {
            $this->insertJob('https://example.com/report-' . $i, 'done');
        }

        $body = $this->renderAction('list');

        self::assertSame(self::JOBS_PER_PAGE, substr_count($body, '<td class="col-control">'));
        self::assertStringNotContainsString('class="pagination', $body);
    }

    #[Test]
    public function listActionPagesTheJobsWithTheCorePager(): void
    {
        for ($i = 0; $i < self::JOBS_PER_PAGE + 5; ++$i) {
            $this->insertJob('https://example.com/report-' . $i, 'done');
        }

        $first = $this->renderAction('list');
        self::assertSame(self::JOBS_PER_PAGE, substr_count($first, '<td class="col-control">'));
        self::assertMatchesRegularExpression('#<nav aria-labelledby="nrrepurpose-pagination">\s*<ul class="pagination">#', $first);
        self::assertMatchesRegularExpression('#<span id="nrrepurpose-pagination" class="page-link">\s*Records 1 - 25\s*<span class="visually-hidden">, Page 1 of 2</span>#', $first);
        // The next and last links carry an accessible name, not only an icon.
        self::assertMatchesRegularExpression('#<a class="page-link" href="[^"]*currentPage[^"]*=2[^"]*" aria-label="Next" title="Next">#', $first);
        // Core structure: the label and "of N" around the jump form, the form holding only the field.
        self::assertMatchesRegularExpression(
            '#<li class="page-item">\s*<span class="page-link">Page <form class="form-inline" data-global-event="submit" data-action-navigate="\$form=~s/\$value/" data-navigate-value="[^"]*currentPage=%24%5Bvalue%5D"[^>]*>'
            . '<input type="number" name="paginator-target-page" min="1" max="2" value="1" [^>]*aria-label="Go to page" /></form> of 2</span>\s*</li>#',
            $first,
        );

        $second = $this->renderAction('list', ['currentPage' => 2]);
        self::assertSame(5, substr_count($second, '<td class="col-control">'));
        self::assertMatchesRegularExpression('#<span id="nrrepurpose-pagination" class="page-link">\s*Records 26 - 30\s*<span class="visually-hidden">, Page 2 of 2</span>#', $second);
    }

    #[Test]
    public function listActionTreatsAPageBelowOneAsTheFirstPage(): void
    {
        $this->insertJob('https://example.com/report', 'done');

        self::assertSame(1, substr_count($this->renderAction('list', ['currentPage' => 0]), '<td class="col-control">'));
    }

    /**
     * A page number from the URL beyond the last page shows the last page. Extbase maps a numeric
     * string above PHP_INT_MAX to PHP_INT_MAX, and the core paginator multiplies the page number by
     * the page size before it clamps; on PHP 8.5 that float-to-int cast raises a warning, which the
     * debug preset (exceptionalErrors includes E_WARNING) turns into an exception.
     */
    #[Test]
    public function listActionShowsTheLastPageForAPageNumberTooLargeForAnInteger(): void
    {
        for ($i = 0; $i < self::JOBS_PER_PAGE + 5; ++$i) {
            $this->insertJob('https://example.com/report-' . $i, 'done');
        }

        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_WARNING);
        try {
            $body = $this->renderAction('list', ['currentPage' => '99999999999999999999']);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertSame(5, substr_count($body, '<td class="col-control">'));
        self::assertMatchesRegularExpression('#<span id="nrrepurpose-pagination" class="page-link">\s*Records 26 - 30\s*<span class="visually-hidden">, Page 2 of 2</span>#', $body);
    }

    #[Test]
    public function listActionRunsTheSameNumberOfQueriesForOneJobAsForMoreThanAPage(): void
    {
        $one  = $this->countListQueries(1);
        $many = $this->countListQueries(40);

        self::assertGreaterThan(0, $one, 'The query counter recorded nothing: the driver middleware is not attached.');
        self::assertSame($one, $many, "The job list runs queries per row:\n" . implode("\n", QueryCountingMiddleware::$queries));
        // The paginator's two COUNTs, the page of jobs and the grouped artifact summaries.
        self::assertLessThanOrEqual(4, $many, implode("\n", QueryCountingMiddleware::$queries));
    }

    /** Renders the list over $jobs jobs of two artifacts each and returns the queries the action ran. */
    private function countListQueries(int $jobs): int
    {
        foreach (['tx_nrrepurpose_domain_model_job', 'tx_nrrepurpose_domain_model_artifact'] as $table) {
            GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)->truncate($table);
        }

        for ($i = 0; $i < $jobs; ++$i) {
            $job = $this->insertJob('https://example.com/report-' . $i, 'done');
            $this->insertArtifact($job, ['type' => 'podcast']);
            $this->insertArtifact($job, ['type' => 'schaubild', 'status' => 'failed']);
        }

        $backendUser     = $this->setUpBackendUser(self::ADMIN);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $request         = $this->createBackendRequest('list');
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);
        $controller = $this->get(JobController::class);
        self::assertInstanceOf(JobController::class, $controller);
        // The persistence session caches mapped objects; a warm one would hide the per-row loads.
        $this->get(PersistenceManagerInterface::class)->clearState();

        QueryCountingMiddleware::$queries = [];
        $response                         = $controller->processRequest($request);
        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $rows = min($jobs, self::JOBS_PER_PAGE);
        self::assertSame($rows, substr_count($body, '<td class="col-control">'));
        // Every row still shows its summaries: the finished podcast and the failed Schaubild.
        self::assertSame($rows, substr_count($body, '<span class="text-success" role="img" title="Podcast: Done"'));
        self::assertSame($rows, substr_count($body, '<span class="text-danger" role="img" title="Schaubild: Failed"'));

        // The backend session write-back varies between requests and has nothing to do with the list.
        // The identifier is quoted per platform: "be_sessions" on SQLite, `be_sessions` on MariaDB.
        QueryCountingMiddleware::$queries = array_values(array_filter(
            QueryCountingMiddleware::$queries,
            static fn (string $sql): bool => preg_match('/^UPDATE [`"]?be_sessions[`"]? /', $sql) !== 1,
        ));

        return count(QueryCountingMiddleware::$queries);
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
     * blocks any other; and the module body has no <style> element and no style attribute at all.
     * The backend CSP allows inline styles, so either would override
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

        // The rendered page read with the HTML5 parser TYPO3 core installs, so attribute spelling
        // (quotes, missing whitespace) cannot hide a style attribute from the check.
        $document   = (new HTML5(['disable_html_ns' => true]))->loadHTML($body);
        $moduleBody = null;
        foreach ($document->getElementsByTagName('div') as $div) {
            if (in_array('module-body', explode(' ', $div->getAttribute('class')), true)) {
                $moduleBody = $div;
                break;
            }
        }

        self::assertInstanceOf(DOMElement::class, $moduleBody, $action . ': module body not found');

        $styled = [];
        foreach ($moduleBody->getElementsByTagName('*') as $element) {
            if ($element->tagName === 'style' || $element->hasAttribute('style')) {
                $styled[] = '<' . $element->tagName . ' style="' . $element->getAttribute('style') . '">';
            }
        }

        self::assertSame([], $styled, $action . ': inline style in the module body');
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

    /** @return array<string, array{0: int, 1: string, 2: string}> */
    public static function permittedDecisions(): array
    {
        return [
            'admin approves'    => [self::ADMIN, 'approved', 'The artifact is approved.'],
            'admin rejects'     => [self::ADMIN, 'rejected', 'The artifact is rejected.'],
            'reviewer approves' => [self::REVIEWER, 'approved', 'The artifact is approved.'],
            'reviewer rejects'  => [self::REVIEWER, 'rejected', 'The artifact is rejected.'],
        ];
    }

    #[Test]
    #[DataProvider('permittedDecisions')]
    public function aPermittedUserRecordsTheDecision(int $user, string $decision, string $message): void
    {
        $job      = $this->insertJob('https://example.com/report', 'done');
        $artifact = $this->insertArtifact($job, ['type' => 'podcast']);
        $before   = time();

        $response = $this->dispatch('review', ['artifact' => $artifact, 'job' => $job, 'decision' => $decision], $user, 'POST');

        $this->assertRedirectsToJob($response, $job);
        self::assertSame([[$message, ContextualFeedbackSeverity::OK]], $this->flashMessages());
        $state = $this->reviewState($artifact);
        self::assertSame($decision, $state['review_status']);
        self::assertSame($user, $state['reviewed_by']);
        self::assertGreaterThanOrEqual($before, $state['reviewed_at']);
        self::assertSame([], RecordingLogWriter::$records);
    }

    #[Test]
    public function aPermittedUserCannotSendTheOpenDecision(): void
    {
        $job      = $this->insertJob('https://example.com/report', 'done');
        $artifact = $this->insertArtifact($job, ['type' => 'podcast', 'review_status' => 'approved', 'reviewed_by' => 1, 'reviewed_at' => 1790000000]);

        // ReviewStatus::Open is the empty string; 'open' is no decision at all and neither may reset one.
        $response = $this->dispatch('review', ['artifact' => $artifact, 'job' => $job, 'decision' => 'open'], self::REVIEWER, 'POST');

        $this->assertRedirectsToJob($response, $job);
        self::assertSame([['Unknown review decision.', ContextualFeedbackSeverity::ERROR]], $this->flashMessages());
        self::assertSame(['review_status' => 'approved', 'reviewed_by' => 1, 'reviewed_at' => 1790000000, 'publish_status' => '', 'publish_at' => 0], $this->reviewState($artifact));
        self::assertSame([], RecordingLogWriter::$records);
    }

    #[Test]
    public function aUserWithoutThePermissionIsRefusedBeforeTheDecisionIsRead(): void
    {
        $job      = $this->insertJob('https://example.com/report', 'done');
        $artifact = $this->insertArtifact($job, ['type' => 'podcast']);

        $response = $this->dispatch('review', ['artifact' => $artifact, 'job' => $job, 'decision' => 'open'], self::EDITOR, 'POST');

        $this->assertRedirectsToJob($response, $job);
        self::assertSame(
            [['You may not approve artifacts. An administrator grants "Approve artifacts" in the backend group.', ContextualFeedbackSeverity::ERROR]],
            $this->flashMessages(),
        );
        self::assertSame(['review_status' => '', 'reviewed_by' => 0, 'reviewed_at' => 0, 'publish_status' => '', 'publish_at' => 0], $this->reviewState($artifact));
        self::assertCount(1, RecordingLogWriter::$records);
    }

    #[Test]
    public function scheduleActionPutsAnApprovedPostOnTheScheduleAtTheServerTime(): void
    {
        $job      = $this->insertJob('https://example.com/report', 'done');
        $artifact = $this->insertArtifact($job, ['type' => 'social_post', 'variant' => 'linkedin', 'review_status' => 'approved']);
        $expected = (new DateTimeImmutable('2026-10-01 09:30:00', new DateTimeZone(date_default_timezone_get())))->getTimestamp();

        $response = $this->dispatch('schedule', ['artifact' => $artifact, 'job' => $job, 'publishAt' => '2026-10-01T09:30'], self::REVIEWER, 'POST');

        $this->assertRedirectsToJob($response, $job);
        self::assertSame([['The post is scheduled.', ContextualFeedbackSeverity::OK]], $this->flashMessages());
        $state = $this->reviewState($artifact);
        self::assertSame('scheduled', $state['publish_status']);
        self::assertSame($expected, $state['publish_at']);
    }

    #[Test]
    public function scheduleActionRefusesAnUnparsableTime(): void
    {
        $job      = $this->insertJob('https://example.com/report', 'done');
        $artifact = $this->insertArtifact($job, ['type' => 'social_post', 'variant' => 'linkedin', 'review_status' => 'approved']);

        $response = $this->dispatch('schedule', ['artifact' => $artifact, 'job' => $job, 'publishAt' => 'tomorrow 9am'], self::REVIEWER, 'POST');

        $this->assertRedirectsToJob($response, $job);
        self::assertSame([['Enter a date and time for publishing.', ContextualFeedbackSeverity::ERROR]], $this->flashMessages());
        self::assertSame(['review_status' => 'approved', 'reviewed_by' => 0, 'reviewed_at' => 0, 'publish_status' => '', 'publish_at' => 0], $this->reviewState($artifact));
    }

    #[Test]
    public function unscheduleActionTakesThePostOffTheSchedule(): void
    {
        $job      = $this->insertJob('https://example.com/report', 'done');
        $artifact = $this->insertArtifact($job, ['type' => 'social_post', 'variant' => 'linkedin', 'review_status' => 'approved', 'publish_status' => 'scheduled', 'publish_at' => 1790000000]);

        $response = $this->dispatch('unschedule', ['artifact' => $artifact, 'job' => $job], self::REVIEWER, 'POST');

        $this->assertRedirectsToJob($response, $job);
        self::assertSame([['The post is no longer scheduled.', ContextualFeedbackSeverity::OK]], $this->flashMessages());
        $state = $this->reviewState($artifact);
        self::assertSame('', $state['publish_status']);
        self::assertSame(0, $state['publish_at']);
        self::assertSame('approved', $state['review_status']);
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
