<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\ViewHelpers;

use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Resolves a sys_file uid to a site-absolute web path (leading slash) so the backend result
 * view can embed and offer downloads of generated artifacts. The artifacts are stored with raw
 * file uids (not FileReferences), so this wraps ResourceFactory::getFileObject(). Returns an
 * empty string for uid 0 or a missing/unresolvable file, letting templates guard with <f:if>.
 */
final class PublicUrlViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('fileUid', 'int', 'sys_file uid of the artifact file', true);
    }

    public function render(): string
    {
        $fileUid = (int) $this->arguments['fileUid'];
        if ($fileUid <= 0) {
            return '';
        }

        try {
            $file = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($fileUid);
            $publicUrl = $file->getPublicUrl();
        } catch (\Throwable) {
            return '';
        }

        return \is_string($publicUrl) && $publicUrl !== ''
            ? PathUtility::getAbsoluteWebPath($publicUrl)
            : '';
    }
}
