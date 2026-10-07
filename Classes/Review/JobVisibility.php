<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Review;

use Netresearch\NrRepurpose\Domain\Model\Job;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Which jobs a backend user of the module sees: administrators and users with the
 * approve permission see every job, because approving and scheduling the artifacts of
 * all editors is their task; every other user sees only the jobs they created. A job
 * created without a backend user (be_user 0, e.g. on the command line) belongs to
 * nobody and is visible to administrators and reviewers only.
 */
final readonly class JobVisibility
{
    public function __construct(private ReviewPermission $reviewPermission) {}

    public function seesAllJobs(?BackendUserAuthentication $user): bool
    {
        return $this->reviewPermission->allows($user);
    }

    /** The uid whose jobs the user sees when not all of them; 0 for no user. */
    public function ownerUid(?BackendUserAuthentication $user): int
    {
        return $user instanceof BackendUserAuthentication ? (int) $user->getUserId() : 0;
    }

    public function maySee(?BackendUserAuthentication $user, Job $job): bool
    {
        if ($this->seesAllJobs($user)) {
            return true;
        }

        $owner = $this->ownerUid($user);

        return $owner > 0 && $job->getBeUser() === $owner;
    }
}
