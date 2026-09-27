<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Tests\Unit\Fixture;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A real Guzzle client on the default handler stack (redirect middleware
 * included) whose transport answers from a queue, so a test sees what the
 * request options actually do. No network.
 */
final readonly class QueuedHttpClient
{
    public MockHandler $handler;

    public Client $client;

    public function __construct(ResponseInterface|Throwable ...$queue)
    {
        $this->handler = new MockHandler($queue);
        $this->client  = new Client(['handler' => HandlerStack::create($this->handler)]);
    }

    /** @param array<string, string> $headers */
    public static function answering(int $status, string $body, array $headers = []): self
    {
        return new self(new Response($status, $headers, $body));
    }

    /** Number of queued answers the client has not consumed yet. */
    public function unconsumed(): int
    {
        return $this->handler->count();
    }
}
