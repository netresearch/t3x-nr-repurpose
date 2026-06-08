<?php

declare(strict_types=1);

use Netresearch\NrRepurpose\Command\GenerateCommand;

return [
    'nr_repurpose:generate' => [
        'class' => GenerateCommand::class,
        'schedulable' => false,
    ],
];
