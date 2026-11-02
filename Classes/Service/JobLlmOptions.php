<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Service;

use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrRepurpose\Domain\ValueObject\JobSnapshot;

/**
 * Names the job's site on a text call (ADR-012), so nr-llm treats the call as made for that
 * site (nr-llm ADR-244): on the instance default, the call runs on the configuration the site
 * names in `nrllm.configuration`; on the "nr_repurpose_text" configuration, the site selects the
 * term lists bound to it. A job without a site (URL, PDF) leaves the options as they are.
 */
final class JobLlmOptions
{
    public static function forJob(ChatOptions $options, JobSnapshot $job): ChatOptions
    {
        return $job->site === '' ? $options : $options->withSite($job->site);
    }
}
