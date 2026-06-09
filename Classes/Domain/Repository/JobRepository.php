<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Domain\Repository;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * @extends Repository<\Netresearch\NrRepurpose\Domain\Model\Job>
 */
class JobRepository extends Repository
{
    protected $defaultOrderings = ['crdate' => QueryInterface::ORDER_DESCENDING];

    /**
     * The backend "Repurpose" module lists every job regardless of where it was created
     * in the page tree, so storage-page filtering must be off — otherwise jobs (stored on pid 0
     * by the queue) can vanish from the list depending on the module's page context.
     */
    public function initializeObject(): void
    {
        $querySettings = GeneralUtility::makeInstance(Typo3QuerySettings::class);
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
    }
}
