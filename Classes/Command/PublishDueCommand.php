<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Command;

use Netresearch\NrRepurpose\Social\DuePostPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sends the approved social posts whose time has come. Meant to run every few minutes
 * from the scheduler or cron:
 *   vendor/bin/typo3 nr_repurpose:publish-due.
 *
 * Without a publishing channel (extension setting socialWebhookUrl) it reports how many
 * posts are due and changes nothing. A post the channel refuses is marked failed; the
 * command still succeeds, because the other posts went out.
 */
#[AsCommand(name: 'nr_repurpose:publish-due', description: 'Send the approved social posts whose publishing time has come')]
final class PublishDueCommand extends Command
{
    public function __construct(private readonly DuePostPublisher $publisher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->publisher->publishDue(time());

        if (!$report->channelConfigured) {
            $output->writeln(sprintf(
                '<comment>%d post(s) due, none sent: no publishing channel configured (extension setting socialWebhookUrl).</comment>',
                $report->due,
            ));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('%d post(s) due, %d published, %d failed.', $report->due, $report->published, $report->failed));

        return Command::SUCCESS;
    }
}
