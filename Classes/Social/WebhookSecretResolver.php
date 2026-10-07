<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Social;

use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Reads the webhook signing secret from nr-vault by the identifier in the extension
 * setting `socialWebhookSecretIdentifier`.
 *
 * `nr_repurpose:publish-due` runs on the command line without an authenticated
 * backend user, where nr-vault refuses every read without an actor. With
 * `technicalBeUserUid` set, the read runs as that backend user, as the generation
 * job does (GenerationOrchestrator::process()).
 */
final readonly class WebhookSecretResolver
{
    public function __construct(
        private VaultServiceInterface $vault,
        private TechnicalActorContextInterface $technicalActor,
        private ExtensionConfiguration $extensionConfiguration,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws SocialPublishException when the secret cannot be read or does not exist
     */
    public function resolve(string $identifier): string
    {
        $actorUid = $this->technicalActorUid();

        try {
            $secret = $actorUid > 0
                ? $this->technicalActor->runAs($actorUid, fn (): ?string => $this->vault->retrieve($identifier))
                : $this->vault->retrieve($identifier);
        } catch (Throwable $e) {
            // nr-vault's message names the identifier and the reason; the stored refusal stays fixed.
            $this->logger->error('Webhook signing secret could not be read from nr-vault', ['identifier' => $identifier, 'exception' => $e]);

            throw new SocialPublishException('The webhook signing secret could not be read from nr-vault', 1790410007, $e);
        }

        if (!is_string($secret) || $secret === '') {
            $this->logger->error('Webhook signing secret not found in nr-vault', ['identifier' => $identifier]);

            throw new SocialPublishException('The webhook signing secret was not found in nr-vault', 1790410008);
        }

        return $secret;
    }

    private function technicalActorUid(): int
    {
        try {
            return (int) $this->extensionConfiguration->get('nr_repurpose', 'technicalBeUserUid');
        } catch (Throwable) {
            return 0;
        }
    }
}
