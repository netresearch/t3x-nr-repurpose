<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Review;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Who may approve, reject and schedule artifacts: administrators, and backend users
 * whose groups grant the custom permission "nrrepurpose:approve_artifacts".
 * BackendUserAuthentication::check() answers true for an administrator itself.
 */
final class ReviewPermission
{
    public const string OPTION = 'nrrepurpose:approve_artifacts';

    public function allows(?BackendUserAuthentication $user): bool
    {
        if (!$user instanceof BackendUserAuthentication) {
            return false;
        }

        return $user->check('custom_options', self::OPTION);
    }
}
