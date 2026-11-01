<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Source;

use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Whether a backend user may use a page or a file as a job source. The same checks run when the
 * form is submitted, for the requesting user, and in the worker, for the job's owner.
 *
 * The file check reads the owner's mounts and permissions instead of asking the storage:
 * ResourceStorage objects are shared within a process, StoragePermissionsAspect configures them
 * for $GLOBALS['BE_USER'], and a file mount added for one job's owner would stay on the storage
 * for the next job the long-running worker handles. Asking the owner's own records leaves the
 * shared objects untouched.
 */
final readonly class SourceAccess
{
    /**
     * The backend user with this uid, with their groups loaded, or null when there is no such
     * enabled user. Same idiom as CapabilityGrantResolver: core offers no public way to load a
     * user by uid, and this instance leaves $GLOBALS['BE_USER'] alone.
     */
    public function owner(int $beUserUid): ?BackendUserAuthentication
    {
        if ($beUserUid <= 0) {
            return null;
        }

        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        /** @phpstan-ignore method.internal */
        $user->setBeUserByUid($beUserUid);
        /** @phpstan-ignore property.internal, property.internal */
        if (!is_array($user->user) || $user->user === []) {
            return null;
        }

        /** @phpstan-ignore method.internal */
        $user->fetchGroupData();

        return $user;
    }

    /**
     * In one of the user's web mounts, and the page's permissions let them see it.
     *
     * @param array<string, mixed> $page a pages row
     */
    public function mayReadPage(BackendUserAuthentication $user, array $page): bool
    {
        return $user->isInWebMount($page) !== null
            && $user->doesUserHaveAccess($page, Permission::PAGE_SHOW);
    }

    /**
     * The user's permissions include reading files, and the file lies inside one of their file
     * mounts: the conditions ResourceStorage::checkFileActionPermission('read') applies to a user,
     * as far as they depend on the user. An administrator is not limited by either.
     */
    public function mayReadFile(BackendUserAuthentication $user, File $file): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return ($user->getFilePermissions()['readFile'] ?? false) === true && $this->isInFileMount($user, $file);
    }

    private function isInFileMount(BackendUserAuthentication $user, File $file): bool
    {
        $storage = $file->getStorage();
        /** @phpstan-ignore method.internal */
        foreach ($user->getFileMountRecords() as $mount) {
            $identifier = is_array($mount) ? ($mount['identifier'] ?? '') : '';
            if (!is_string($identifier) || !str_starts_with($identifier, $storage->getUid() . ':')) {
                continue;
            }

            try {
                $folder = GeneralUtility::makeInstance(ResourceFactory::class)->getFolderObjectFromCombinedIdentifier($identifier);
                if ($storage->isWithinFolder($folder, $file)) {
                    return true;
                }
            } catch (Throwable) {
                // A mount whose folder is gone grants nothing; the next one may still apply.
                continue;
            }
        }

        return false;
    }
}
