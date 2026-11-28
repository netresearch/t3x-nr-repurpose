<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Functional\Fixtures;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

/** Fails the first $failures sends, then records like RecordingMessageBus. */
final class FailingMessageBus implements MessageBusInterface
{
    public int $attempts = 0;

    /** @var list<object> */
    public array $dispatched = [];

    public function __construct(private readonly int $failures) {}

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if (++$this->attempts <= $this->failures) {
            throw new TransportException('queue table unavailable');
        }

        $this->dispatched[] = $message;

        return new Envelope($message);
    }
}
