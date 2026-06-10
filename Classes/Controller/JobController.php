<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Controller;

use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Domain\ValueObject\PromptSnippetSelection;
use Netresearch\NrRepurpose\Service\JobSubmissionService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
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
    ) {}

    public function initializeAction(): void
    {
        // Build the ModuleTemplate here, not in __construct (controller is reused across actions).
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
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
            'toneOptions' => $this->snippetOptions('tone_of_voice'),
            'personaOptions' => $this->snippetOptions('persona'),
            'layoutOptions' => $this->snippetOptions('layout'),
            'styleOptions' => $this->snippetOptions('style'),
        ]);

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

        $beUser = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $this->jobSubmissionService->submit($newJob, $beUser);
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
                ?? sprintf('Job #%d', $job->getUid()),
        );
        $this->moduleTemplate->assign('job', $job);

        return $this->moduleTemplate->renderResponse('Job/Show');
    }

    private function moduleTitle(): string
    {
        return LocalizationUtility::translate('module.title', 'nr_repurpose') ?? 'Repurpose';
    }

    /**
     * uid => name map of the active snippets carrying one tag, for a form select.
     *
     * @return array<int, string>
     */
    private function snippetOptions(string $tag): array
    {
        $options = [];
        foreach ($this->promptSnippetRepository->findActiveByTag($tag) as $snippet) {
            $options[(int) $snippet->getUid()] = $snippet->getName();
        }

        return $options;
    }
}
