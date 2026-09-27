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
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Domain\ValueObject\PromptSnippetSelection;
use Netresearch\NrRepurpose\Review\ArtifactReviewService;
use Netresearch\NrRepurpose\Review\ReviewPermission;
use Netresearch\NrRepurpose\Review\ReviewRefusedException;
use Netresearch\NrRepurpose\Service\JobSubmissionService;
use Netresearch\NrRepurpose\Social\SocialPublisherInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

#[AsController]
class JobController extends ActionController
{
    protected ModuleTemplate $moduleTemplate;

    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly JobRepository $jobRepository,
        protected readonly JobSubmissionService $jobSubmissionService,
        protected readonly PromptSnippetRepository $promptSnippetRepository,
        protected readonly ArtifactReviewService $reviewService,
        protected readonly ReviewPermission $reviewPermission,
        protected readonly SocialPublisherInterface $socialPublisher,
        protected readonly PageRenderer $pageRenderer,
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

    public function listAction(): ResponseInterface
    {
        $this->moduleTemplate->setTitle($this->moduleTitle());
        $this->moduleTemplate->assign('jobs', $this->jobRepository->findAll());

        return $this->moduleTemplate->renderResponse('Job/List');
    }

    public function newAction(): ResponseInterface
    {
        $this->moduleTemplate->setTitle(
            $this->moduleTitle(),
            LocalizationUtility::translate('new.title', 'nr_repurpose') ?? '',
        );
        // One uid => name option map per snippet tag for the form's snippet selectors.
        // An unconfigured tag yields an empty map; the select then only offers "(none)".
        $this->moduleTemplate->assignMultiple([
            'audienceOptions' => $this->snippetOptions('audience'),
            'toneOptions'     => $this->snippetOptions('tone_of_voice'),
            'personaOptions'  => $this->snippetOptions('persona'),
            'layoutOptions'   => $this->snippetOptions('layout'),
            'styleOptions'    => $this->snippetOptions('style'),
        ]);
        // Submit feedback (disable the button, show a spinner) as an ES module: the
        // backend Content Security Policy blocks an inline <script> without a nonce.
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-repurpose/job-new.js');

        return $this->moduleTemplate->renderResponse('Job/New');
    }

    /**
     * The snippet* arguments are the form's prompt-snippet selectors (plain request arguments,
     * not Job properties — the Job stores the consolidated selection as one JSON snapshot).
     * 0 = "(none)"; the selection VO drops zeros, duplicates and a fourth-plus persona.
     */
    public function createAction(
        Job $newJob,
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

        $this->jobSubmissionService->submit($newJob, $this->backendUser()?->getUserId() ?? 0);
        $this->addFlashMessage(
            LocalizationUtility::translate('job.created', 'nr_repurpose') ?? 'Job created and queued for generation.',
        );

        return $this->redirect('list');
    }

    public function showAction(Job $job): ResponseInterface
    {
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

        return $this->reviewStep($job, function () use ($artifact, $status): string {
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
        return $this->reviewStep($job, function () use ($artifact, $publishAt): string {
            $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $publishAt, new DateTimeZone(date_default_timezone_get()));
            $this->reviewService->schedule($artifact, $time === false ? 0 : $time->getTimestamp());

            return 'publish.done.scheduled';
        });
    }

    public function unscheduleAction(int $artifact, int $job): ResponseInterface
    {
        return $this->reviewStep($job, function () use ($artifact): string {
            $this->reviewService->unschedule($artifact);

            return 'publish.done.unscheduled';
        });
    }

    /** The social posts on the schedule and their publishing state, across all jobs. */
    public function planAction(): ResponseInterface
    {
        $this->moduleTemplate->setTitle(
            $this->moduleTitle(),
            LocalizationUtility::translate('plan.title', 'nr_repurpose') ?? 'Social planning',
        );
        $this->moduleTemplate->assignMultiple([
            'posts'             => $this->reviewService->planned(),
            'channelConfigured' => $this->socialPublisher->isConfigured(),
            'now'               => time(),
        ]);

        return $this->moduleTemplate->renderResponse('Job/Plan');
    }

    /**
     * Runs one review or scheduling step for a user with the approve permission and
     * returns to the job; the step returns the label of its success message.
     *
     * @param callable(): string $step
     */
    private function reviewStep(int $job, callable $step): ResponseInterface
    {
        if (!$this->reviewPermission->allows($this->backendUser())) {
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
