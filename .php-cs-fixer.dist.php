<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

/*
 * The shared ruleset from netresearch/typo3-ci-workflows, not a local one.
 *
 * Before this file existed, php-cs-fixer found no configuration and wrote
 * itself a starter with `'@auto' => true`. Running the cgl suite then
 * reformatted 30 files against a style the repository does not use — and
 * reported SUCCESS, because in fix mode "changed something" is not an error.
 * That is also why ci.yml had `run-cgl: false`: switched on, the gate would
 * have fought the codebase.
 *
 * At the repository root rather than under Build/: php-cs-fixer discovers
 * `.php-cs-fixer.dist.php` here by itself, so runTests.sh and the composer
 * script both work without a --config flag, and there is only one path that
 * can go stale.
 */

$createConfig = require __DIR__ . '/.Build/vendor/netresearch/typo3-ci-workflows/config/php-cs-fixer/config.php';

return $createConfig(<<<'EOF'
    Copyright (c) 2025-2026 Netresearch DTT GmbH
    SPDX-License-Identifier: GPL-2.0-or-later
    EOF, __DIR__);
