<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** Keeps every dispatched message with its stamps instead of sending it anywhere. */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<array{message: object, stamps: array<int, object>}> */
    public array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = ['message' => $message, 'stamps' => $stamps];

        return new Envelope($message);
    }
}
