<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrRepurpose\Domain\Enum\ReviewStatus;
use Netresearch\NrRepurpose\Domain\Enum\SourceType;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\ArtifactRepository;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Domain\ValueObject\PromptSnippetSelection;
use Netresearch\NrRepurpose\Review\ArtifactReviewService;
use Netresearch\NrRepurpose\Review\JobVisibility;
use Netresearch\NrRepurpose\Review\ReviewPermission;
use Netresearch\NrRepurpose\Review\ReviewRefusedException;
use Netresearch\NrRepurpose\Service\JobSubmissionService;
use Netresearch\NrRepurpose\Social\SocialPublisherInterface;
use Netresearch\NrRepurpose\Source\JobSourceAssigner;
use Netresearch\NrRepurpose\Source\PageSourceLimits;
use Netresearch\NrRepurpose\Source\PageTreeWalker;
use Netresearch\NrRepurpose\Source\SourceAccess;
use Netresearch\NrRepurpose\Source\SourceRefusedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Pagination\QueryResultPaginator;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

#[AsController]
class JobController extends ActionController
{
    /** Rows per page of the job list. */
    private const int JOBS_PER_PAGE = 25;

    /** The hidden inputs of the new-job form the element browsers fill. */
    private const string PAGE_FIELD = 'nrrepurpose-sourcePage';

    private const string FILE_FIELD = 'nrrepurpose-sourceFile';

    protected ModuleTemplate $moduleTemplate;

    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly JobRepository $jobRepository,
        protected readonly ArtifactRepository $artifactRepository,
        protected readonly JobSubmissionService $jobSubmissionService,
        protected readonly PromptSnippetRepository $promptSnippetRepository,
        protected readonly ArtifactReviewService $reviewService,
        protected readonly ReviewPermission $reviewPermission,
        protected readonly SocialPublisherInterface $socialPublisher,
        protected readonly PageRenderer $pageRenderer,
        protected readonly LoggerInterface $logger,
        protected readonly JobVisibility $jobVisibility,
        protected readonly JobSourceAssigner $sourceAssigner,
        protected readonly PageTreeWalker $pageTreeWalker,
        protected readonly SourceAccess $sourceAccess,
        protected readonly PageSourceLimits $pageSourceLimits,
        protected readonly BackendUriBuilder $backendUriBuilder,
    ) {}

    protected function initializeAction(): void
    {
        // Build the ModuleTemplate here, not in __construct (controller is reused across actions).
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
        // Layout rules the core backend CSS has no utility for (media sizes, the story strip,
        // wrapped prompt text). Colours come from the core custom properties, so both schemes work.
        $this->pageRenderer->addCssFile('EXT:nr_repurpose/Resources/Public/Css/backend.css');
    }

    /** @param int $currentPage 1-based; a lower value shows the first page, a higher one the last. */
    public function listAction(int $currentPage = 1): ResponseInterface
    {
        $this->moduleTemplate->setTitle($this->moduleTitle());
        // The upper bound keeps the paginator's offset (page size × page) an integer; it clamps to
        // the last page itself.
        $currentPage = min(max(1, $currentPage), intdiv(PHP_INT_MAX, self::JOBS_PER_PAGE));
        $user        = $this->backendUser();
        $jobs        = $this->jobRepository->findVisibleTo($this->jobVisibility->ownerUid($user), $this->jobVisibility->seesAllJobs($user));
        $paginator   = new QueryResultPaginator($jobs, $currentPage, self::JOBS_PER_PAGE);
        $jobs        = [];
        foreach ($paginator->getPaginatedItems() as $job) {
            // The query result holds Job objects only; the check gives them their type.
            if ($job instanceof Job) {
                $jobs[] = $job;
            }
        }

        $this->moduleTemplate->assignMultiple([
            'jobs'       => $jobs,
            'paginator'  => $paginator,
            'pagination' => new SimplePagination($paginator),
            // One grouped query for the artifact column instead of loading each row's artifacts.
            'artifactSummaries' => $this->artifactRepository->findTypeSummariesByJobs(
                array_map(static fn (Job $job): int => (int) $job->getUid(), $jobs),
            ),
        ]);

        return $this->moduleTemplate->renderResponse('Job/List');
    }

    public function newAction(): ResponseInterface
    {
        $id = $this->request->getQueryParams()['id'] ?? 0;

        return $this->renderNewForm(new Job(), is_numeric($id) ? (int) $id : 0);
    }

    /**
     * The form, for a new job or again with what a refused submission held.
     *
     * @param int                $pageUid  the page the picker starts with, when the user may read it
     * @param array<string, int> $selected the plain request arguments of the form
     */
    private function renderNewForm(Job $job, int $pageUid, array $selected = []): ResponseInterface
    {
        $this->moduleTemplate->setTitle(
            $this->moduleTitle(),
            LocalizationUtility::translate('new.title', 'nr_repurpose') ?? '',
        );
        $page = $this->pickablePage($pageUid);
        // The page selected in the module's page tree starts the form on that page.
        if ($page !== null && $selected === []) {
            $job->setSourceTypeEnum(SourceType::Page);
        }

        // One uid => name option map per snippet tag for the form's snippet selectors.
        // An unconfigured tag yields an empty map; the select then only offers "(none)".
        $this->moduleTemplate->assignMultiple([
            'newJob'          => $job,
            'selected'        => $selected,
            'audienceOptions' => $this->snippetOptions('audience'),
            'toneOptions'     => $this->snippetOptions('tone_of_voice'),
            'personaOptions'  => $this->snippetOptions('persona'),
            'layoutOptions'   => $this->snippetOptions('layout'),
            'styleOptions'    => $this->snippetOptions('style'),
            'sourcePage'      => $page,
            'depthOptions'    => $this->depthOptions(),
            'pageBrowserUrl'  => $this->elementBrowserUrl('db', 'pages', self::PAGE_FIELD, $page === null ? [] : ['expandPage' => $page['uid']]),
            'fileBrowserUrl'  => $this->elementBrowserUrl('file', 'pdf', self::FILE_FIELD),
            'maxPages'        => $this->pageSourceLimits->maxPages(),
            'maxChars'        => $this->pageSourceLimits->maxChars(),
        ]);
        // Submit feedback (disable the button, show a spinner) and the source pickers as an ES module:
        // the backend Content Security Policy blocks an inline <script> without a nonce.
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-repurpose/job-new.js');

        return $this->moduleTemplate->renderResponse('Job/New');
    }

    /**
     * The snippet* arguments are the form's prompt-snippet selectors (plain request arguments,
     * not Job properties — the Job stores the consolidated selection as one JSON snapshot).
     * 0 = "(none)"; the selection VO drops zeros, duplicates and a fourth-plus persona.
     *
     * The source* arguments are what the form's pickers chose: JobSourceAssigner reads the records
     * behind them and fills the job's source, whatever the form sent.
     */
    public function createAction(
        Job $newJob,
        int $sourcePage = 0,
        int $sourceDepth = 0,
        int $sourceFile = 0,
        int $snippetAudience = 0,
        int $snippetTone = 0,
        int $snippetPersona1 = 0,
        int $snippetPersona2 = 0,
        int $snippetPersona3 = 0,
        int $snippetSchaubildLayout = 0,
        int $snippetSchaubildStyle = 0,
        int $snippetStoryLayout = 0,
        int $snippetStoryStyle = 0,
    ): ResponseInterface {
        $newJob->setPromptSnippetSelection(new PromptSnippetSelection(
            audience: $snippetAudience,
            tone: $snippetTone,
            personas: [$snippetPersona1, $snippetPersona2, $snippetPersona3],
            schaubildLayout: $snippetSchaubildLayout,
            schaubildStyle: $snippetSchaubildStyle,
            storyLayout: $snippetStoryLayout,
            storyStyle: $snippetStoryStyle,
        ));

        try {
            $source = $this->sourceAssigner->assign($newJob, $this->backendUser(), $sourcePage, $sourceDepth, $sourceFile, $this->requestOrigin());
        } catch (SourceRefusedException $e) {
            $this->logger->warning('Refused the source of a new job: {reason} (backend user {backendUser}, page {page}, file {file})', [
                'reason'      => $e->getMessage(),
                'backendUser' => $this->backendUser()?->getUserId() ?? 0,
                'page'        => $sourcePage,
                'file'        => $sourceFile,
            ]);
            $this->addFlashMessage($this->label($e->getMessage()), '', ContextualFeedbackSeverity::ERROR);

            // The form again with what the editor filled in: only the source has to change.
            return $this->renderNewForm($newJob, $sourcePage, [
                'sourceDepth'            => $sourceDepth,
                'snippetAudience'        => $snippetAudience,
                'snippetTone'            => $snippetTone,
                'snippetPersona1'        => $snippetPersona1,
                'snippetPersona2'        => $snippetPersona2,
                'snippetPersona3'        => $snippetPersona3,
                'snippetSchaubildLayout' => $snippetSchaubildLayout,
                'snippetSchaubildStyle'  => $snippetSchaubildStyle,
                'snippetStoryLayout'     => $snippetStoryLayout,
                'snippetStoryStyle'      => $snippetStoryStyle,
            ]);
        }

        $this->jobSubmissionService->submit($newJob, $this->backendUser()?->getUserId() ?? 0, $source->fileUid);
        $this->addFlashMessage(
            LocalizationUtility::translate('job.created', 'nr_repurpose') ?? 'Job created and queued for generation.',
        );
        if ($source->cutByPageLimit) {
            $this->addFlashMessage(
                LocalizationUtility::translate('job.created.cut', 'nr_repurpose', [$this->pageSourceLimits->maxPages()]) ?? '',
                '',
                ContextualFeedbackSeverity::WARNING,
            );
        }

        return $this->redirect('list');
    }

    public function showAction(Job $job): ResponseInterface
    {
        if (!$this->jobVisibility->maySee($this->backendUser(), $job)) {
            $this->logger->warning('Refused showing job {job}: backend user {backendUser} did not create it and may not see all jobs', [
                'backendUser' => $this->backendUser()?->getUserId() ?? 0,
                'job'         => (int) $job->getUid(),
            ]);
            $this->addFlashMessage($this->label('job.refused.access'), '', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('list');
        }

        $this->moduleTemplate->setTitle(
            $this->moduleTitle(),
            LocalizationUtility::translate('show.title', 'nr_repurpose', [$job->getUid()])
                ?? sprintf('Job #%d', (int) $job->getUid()),
        );
        $this->moduleTemplate->assignMultiple([
            'job'               => $job,
            'snippetSelections' => $this->resolveSnippetSelections($job->getPromptSnippetSelection()),
            'canReview'         => $this->reviewPermission->allows($this->backendUser()),
        ]);

        return $this->moduleTemplate->renderResponse('Job/Show');
    }

    /** Approve or reject a finished artifact. */
    public function reviewAction(int $artifact, int $job, string $decision): ResponseInterface
    {
        $status = ReviewStatus::tryFrom($decision);

        return $this->reviewStep($artifact, $job, function () use ($artifact, $status): string {
            if ($status === null || $status === ReviewStatus::Open) {
                throw new ReviewRefusedException('review.refused.decision', 1790400007);
            }

            $this->reviewService->review($artifact, $status, $this->backendUser()?->getUserId() ?? 0, time());

            return $status === ReviewStatus::Approved ? 'review.done.approved' : 'review.done.rejected';
        });
    }

    /** Schedule an approved social post; $publishAt is a datetime-local value in server time. */
    public function scheduleAction(int $artifact, int $job, string $publishAt = ''): ResponseInterface
    {
        return $this->reviewStep($artifact, $job, function () use ($artifact, $publishAt): string {
            $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $publishAt, new DateTimeZone(date_default_timezone_get()));
            $this->reviewService->schedule($artifact, $time === false ? 0 : $time->getTimestamp());

            return 'publish.done.scheduled';
        });
    }

    public function unscheduleAction(int $artifact, int $job): ResponseInterface
    {
        return $this->reviewStep($artifact, $job, function () use ($artifact): string {
            $this->reviewService->unschedule($artifact);

            return 'publish.done.unscheduled';
        });
    }

    /** The social posts on the schedule and their publishing state, of the jobs the user sees. */
    public function planAction(): ResponseInterface
    {
        $this->moduleTemplate->setTitle(
            $this->moduleTitle(),
            LocalizationUtility::translate('plan.title', 'nr_repurpose') ?? 'Social planning',
        );
        $user = $this->backendUser();
        $this->moduleTemplate->assignMultiple([
            'posts' => $this->reviewService->planned(
                $this->jobVisibility->seesAllJobs($user) ? null : $this->jobVisibility->ownerUid($user),
            ),
            'channelConfigured' => $this->socialPublisher->isConfigured(),
            'now'               => time(),
        ]);

        return $this->moduleTemplate->renderResponse('Job/Plan');
    }

    /**
     * Runs one review or scheduling step for a user with the approve permission and
     * returns to the job; the step returns the label of its success message.
     *
     * A refused attempt is logged here and not in ReviewPermission::allows(): showAction asks
     * the same question for every result view, where "no" only hides the buttons.
     *
     * @param callable(): string $step
     */
    private function reviewStep(int $artifact, int $job, callable $step): ResponseInterface
    {
        if (!$this->reviewPermission->allows($this->backendUser())) {
            $this->logger->warning('Refused {action} of artifact {artifact} (job {job}): backend user {backendUser} lacks the approve permission', [
                'action'      => $this->request->getControllerActionName(),
                'backendUser' => $this->backendUser()?->getUserId() ?? 0,
                'artifact'    => $artifact,
                'job'         => $job,
            ]);
            $this->addFlashMessage($this->label('review.refused.permission'), '', ContextualFeedbackSeverity::ERROR);

            // Extbase builds the backend route of the fixed action "show"; $job is an int, not a URL.
            return $this->redirect('show', null, null, ['job' => $job]); // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        }

        try {
            $this->addFlashMessage($this->label($step()));
        } catch (ReviewRefusedException $e) {
            $this->addFlashMessage($this->label($e->getMessage()), '', ContextualFeedbackSeverity::ERROR);
        }

        // Extbase builds the backend route of the fixed action "show"; $job is an int, not a URL.
        return $this->redirect('show', null, null, ['job' => $job]); // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
    }

    /**
     * A page the user may read, as the form's picker shows it.
     *
     * @return array{uid: int, label: string}|null
     */
    private function pickablePage(int $uid): ?array
    {
        $user = $this->backendUser();
        $page = $this->pageTreeWalker->findPage($uid);
        if ($page === null || !$user instanceof BackendUserAuthentication || !$this->sourceAccess->mayReadPage($user, $page)) {
            return null;
        }

        return ['uid' => $uid, 'label' => JobSourceAssigner::label(is_string($page['title'] ?? null) ? $page['title'] : '', $uid)];
    }

    /** Scheme and host of this request; the worker has none, see PageUrlResolver. */
    private function requestOrigin(): string
    {
        $parameters = $this->request->getAttribute('normalizedParams');

        return $parameters instanceof NormalizedParams ? $parameters->getRequestHost() : '';
    }

    /** @return array<int, string> levels below the picked page => label */
    private function depthOptions(): array
    {
        return [
            0                            => $this->label('new.field.sourceDepth.0'),
            1                            => $this->label('new.field.sourceDepth.1'),
            2                            => $this->label('new.field.sourceDepth.2'),
            3                            => $this->label('new.field.sourceDepth.3'),
            4                            => $this->label('new.field.sourceDepth.4'),
            JobSourceAssigner::MAX_DEPTH => $this->label('new.field.sourceDepth.all'),
        ];
    }

    /**
     * The URL of TYPO3's element browser for job-new.js. useEvents makes it report a pick as an
     * event on its iframe, which needs no FormEngine in the opening window; fieldReference must not
     * be empty, or the browser drops the pick.
     *
     * @param array<string, int> $parameters
     */
    private function elementBrowserUrl(string $mode, string $allowedTypes, string $fieldReference, array $parameters = []): string
    {
        return (string) $this->backendUriBuilder->buildUriFromRoute('wizard_element_browser', [
            'mode'           => $mode,
            'allowedTypes'   => $allowedTypes,
            'fieldReference' => $fieldReference,
            'useEvents'      => 1,
            ...$parameters,
        ]);
    }

    private function label(string $key): string
    {
        return LocalizationUtility::translate($key, 'nr_repurpose') ?? $key;
    }

    /**
     * The one place this controller reads $GLOBALS['BE_USER']: the review permission needs the
     * authentication object for check(), which the Context's backend.user aspect does not expose.
     */
    private function backendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }

    /**
     * Resolve the job's persisted prompt-snippet selection into display rows for the
     * Show view's "Creation parameters" panel. Slot order mirrors the New form; a
     * snippet that was deleted or deactivated since the job was created yields
     * available=false so the view renders "no longer available" instead of silently
     * dropping the slot.
     *
     * @return list<array{label: string, name: string, description: string, available: bool}>
     */
    private function resolveSnippetSelections(PromptSnippetSelection $selection): array
    {
        if ($selection->isEmpty()) {
            return [];
        }

        $byUid = [];
        foreach ($this->promptSnippetRepository->findByUids($selection->selectedUids()) as $snippet) {
            $byUid[(int) $snippet->getUid()] = $snippet;
        }

        $slots = [
            ['audience', $selection->audience],
            ['tone', $selection->tone],
        ];
        foreach ($selection->personas as $uid) {
            $slots[] = ['persona', $uid];
        }

        $slots[] = ['schaubildLayout', $selection->schaubildLayout];
        $slots[] = ['schaubildStyle', $selection->schaubildStyle];
        $slots[] = ['storyLayout', $selection->storyLayout];
        $slots[] = ['storyStyle', $selection->storyStyle];

        $rows = [];
        foreach ($slots as [$label, $uid]) {
            if ($uid <= 0) {
                continue;
            }

            $snippet = $byUid[$uid] ?? null;
            $rows[]  = [
                'label'       => $label,
                'name'        => $snippet?->getName() ?? '',
                'description' => $snippet?->getDescription() ?? '',
                'available'   => $snippet !== null,
            ];
        }

        return $rows;
    }

    private function moduleTitle(): string
    {
        return LocalizationUtility::translate('module.title', 'nr_repurpose') ?? 'Repurpose';
    }

    /**
     * uid => label map of the active snippets carrying one tag, for a form select.
     * The label carries the description ("Name — description") so editors can make
     * an informed choice — the bare name (e.g. a persona's first name) says nothing.
     *
     * @return array<int, string>
     */
    private function snippetOptions(string $tag): array
    {
        $options = [];
        foreach ($this->promptSnippetRepository->findActiveByTag($tag) as $snippet) {
            $label = $snippet->getName();
            if ($snippet->getDescription() !== '') {
                $label .= ' — ' . $snippet->getDescription();
            }

            $options[(int) $snippet->getUid()] = $label;
        }

        return $options;
    }
}
