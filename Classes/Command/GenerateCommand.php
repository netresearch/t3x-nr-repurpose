<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Command;

use Netresearch\NrRepurpose\Service\GenerationOrchestratorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the generation pipeline for a single job synchronously. Useful for ops/CLI runs and
 * for driving an end-to-end test without the async worker:
 *   vendor/bin/typo3 nr_repurpose:generate <jobUid>
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
        $jobUid = (int) $input->getArgument('jobUid');
        $output->writeln(sprintf('<info>Processing job #%d …</info>', $jobUid));
        $this->orchestrator->process($jobUid);
        $output->writeln('<info>Done.</info>');

        return Command::SUCCESS;
    }
}
