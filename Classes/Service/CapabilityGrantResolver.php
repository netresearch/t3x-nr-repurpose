<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrRepurpose\Domain\ValueObject\CapabilityGrants;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reads the job owner's grants the way the backend does: a fresh
 * BackendUserAuthentication for that uid, its groups fetched, then
 * check('custom_options', …), which also answers true for an administrator.
 *
 * The worker runs on the CLI, where $GLOBALS['BE_USER'] is not the job owner
 * (and may be the technical actor of the vault wrapper), so the owner is
 * loaded into its own instance and nothing global is touched.
 */
final readonly class CapabilityGrantResolver implements CapabilityGrantResolverInterface
{
    public const PERMISSION_AUDIO = 'nrrepurpose:generate_audio';

    public const PERMISSION_VISION = 'nrrepurpose:generate_vision';

    public function resolve(int $beUserUid): CapabilityGrants
    {
        if ($beUserUid <= 0) {
            return CapabilityGrants::none();
        }

        // The CLI impersonation idiom nr-llm's ActingBackendUserResolver uses too:
        // setBeUserByUid() honours enable fields (a deleted or disabled user leaves
        // ->user empty), fetchGroupData() builds the group permissions check() reads.
        // Core marks both @internal and offers no public equivalent for a uid.
        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        /** @phpstan-ignore method.internal */
        $user->setBeUserByUid($beUserUid);
        /** @phpstan-ignore property.internal, property.internal */
        if (!is_array($user->user) || $user->user === []) {
            return CapabilityGrants::none();
        }

        /** @phpstan-ignore method.internal */
        $user->fetchGroupData();

        return new CapabilityGrants(
            audio: $user->check('custom_options', self::PERMISSION_AUDIO),
            vision: $user->check('custom_options', self::PERMISSION_VISION),
        );
    }
}
