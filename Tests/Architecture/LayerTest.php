<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Dependency direction of the pipeline (docs/ARCHITECTURE.md): the backend
 * module depends on the domain and the generators, never the other way round.
 *
 * Run by PHPStan through phpat (Build/phpstan.neon), not by PHPUnit.
 */
final class LayerTest
{
    private const string CONTROLLER_NS = 'Netresearch\NrRepurpose\Controller';

    public function testDomainDoesNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrRepurpose\Domain'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace(self::CONTROLLER_NS))
            ->because('The domain is used by the backend module and the worker alike and must not know either.');
    }

    public function testGeneratorsDoNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrRepurpose\Generator'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace(self::CONTROLLER_NS))
            ->because('Generators run in the queue worker, where no backend request or controller exists.');
    }
}
