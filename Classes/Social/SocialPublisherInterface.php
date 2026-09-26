<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Social;

/**
 * A channel that takes a due social post out of TYPO3. The extension ships a webhook
 * channel (a scheduling tool, an automation service or an own endpoint receives the
 * post and puts it on the network); a direct network client can replace it through
 * this interface.
 */
interface SocialPublisherInterface
{
    /** Whether the channel has what it needs to send; without it nothing is sent. */
    public function isConfigured(): bool;

    /**
     * @throws SocialPublishException when the channel does not accept the post
     */
    public function publish(SocialPost $post): void;
}
