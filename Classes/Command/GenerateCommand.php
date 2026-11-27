<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Command;

use Netresearch\NrRepurpose\Exception\RunClaimHeldException;
use Netresearch\NrRepurpose\Service\GenerationOrchestratorInterface;
use Netresearch\NrRepurpose\Service\RunOutcome;
use Netresearch\NrRepurpose\Utility\ScalarCast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the generation pipeline for a single job synchronously. Useful for ops/CLI runs and
 * for driving an end-to-end test without the async worker:
 *   vendor/bin/typo3 nr_repurpose:generate <jobUid>.
 */
#[AsCommand(name: 'nr_repurpose:generate', description: 'Run the generation pipeline for a job uid')]
final class GenerateCommand extends Command
{
    public function __construct(private readonly GenerationOrchestratorInterface $orchestrator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('jobUid', InputArgument::REQUIRED, 'The tx_nrrepurpose_domain_model_job uid to process');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobUid = ScalarCast::int($input->getArgument('jobUid'));
        $output->writeln(sprintf('<info>Processing job #%d …</info>', $jobUid));
        try {
            $outcome = $this->orchestrator->process($jobUid);
        } catch (RunClaimHeldException) {
            $output->writeln(sprintf('<error>Job #%d is being processed by another run; nothing was done.</error>', $jobUid));

            return Command::FAILURE;
        }

        // The outcome of this run, not the job's status: a run that lost the job to another one
        // did not produce it, whatever the other run achieved (#238). A job finished before
        // this run started needed nothing.
        if ($outcome->produced() || $outcome === RunOutcome::AlreadyFinished) {
            $output->writeln(sprintf('<info>Done: %s.</info>', $outcome->value));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<error>Job #%d: %s.</error>', $jobUid, $outcome->value));

        return Command::FAILURE;
    }
}
