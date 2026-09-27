<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Controller;

use Netresearch\NrRepurpose\Controller\JobController;
use Netresearch\NrRepurpose\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

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

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
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

    private function renderNewAction(): string
    {
        return $this->renderAction('new');
    }

    private function renderAction(string $action): string
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/BeUsers.csv');
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $request = $this->createBackendRequest($action);
        // Extbase reads its configuration while the controller is built, so the
        // ConfigurationManager needs the request before the container hands it out.
        $this->get(ConfigurationManagerInterface::class)->setRequest($request);

        $controller = $this->get(JobController::class);
        self::assertInstanceOf(JobController::class, $controller);

        $response = $controller->processRequest($request);
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    private function createBackendRequest(string $action): ExtbaseRequest
    {
        $extbaseParameters = new ExtbaseRequestParameters(JobController::class);
        $extbaseParameters->setPluginName('web_nrrepurpose');
        $extbaseParameters->setControllerExtensionName('NrRepurpose');
        $extbaseParameters->setControllerName('Job');
        $extbaseParameters->setControllerActionName($action);
        $extbaseParameters->setFormat('html');

        $route = $this->get(Router::class)->getRoute('web_nrrepurpose');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/module/web/nr-repurpose', 'GET'))
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
