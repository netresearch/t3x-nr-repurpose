<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Controller;

use Netresearch\NrRepurpose\Domain\Model\Job;
use Netresearch\NrRepurpose\Domain\Repository\JobRepository;
use Netresearch\NrRepurpose\Service\JobSubmissionService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

#[AsController]
class JobController extends ActionController
{
    protected ModuleTemplate $moduleTemplate;

    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly JobRepository $jobRepository,
        protected readonly JobSubmissionService $jobSubmissionService,
    ) {}

    public function initializeAction(): void
    {
        // Build the ModuleTemplate here, not in __construct (controller is reused across actions).
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
    }

    public function listAction(): ResponseInterface
    {
        $this->moduleTemplate->assign('jobs', $this->jobRepository->findAll());

        return $this->moduleTemplate->renderResponse('Job/List');
    }

    public function newAction(): ResponseInterface
    {
        return $this->moduleTemplate->renderResponse('Job/New');
    }

    public function createAction(Job $newJob): ResponseInterface
    {
        $beUser = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $this->jobSubmissionService->submit($newJob, $beUser);
        $this->addFlashMessage('Job created and queued.');

        return $this->redirect('list');
    }

    public function showAction(Job $job): ResponseInterface
    {
        $this->moduleTemplate->assign('job', $job);

        return $this->moduleTemplate->renderResponse('Job/Show');
    }
}
