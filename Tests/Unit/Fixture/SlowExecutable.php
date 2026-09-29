<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

/**
 * An executable that sleeps for five seconds, in a temp directory whose name carries
 * MARKER. Passed as a tool binary with a short timeout, it makes Symfony Process throw
 * ProcessTimedOutException, whose message holds the command line and so the marker.
 */
final readonly class SlowExecutable
{
    public const string MARKER = 'nrrepurpose-secret-dir';

    public string $path;

    private string $dir;

    public function __construct()
    {
        $this->dir  = sys_get_temp_dir() . '/' . self::MARKER . '-' . bin2hex(random_bytes(4));
        $this->path = $this->dir . '/slow-tool';
        mkdir($this->dir, 0o775, true);
        file_put_contents($this->path, "#!/bin/sh\nexec sleep 5\n");
        chmod($this->path, 0o755);
    }

    public function remove(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }
}
