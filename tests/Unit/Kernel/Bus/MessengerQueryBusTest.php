<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel\Bus;

use App\Kernel\Bus\MessengerQueryBus;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class MessengerQueryBusTest extends TestCase
{
    public function test_it_returns_the_result_of_the_dispatched_query(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(
                static fn(object $message): Envelope => new Envelope($message, [new HandledStamp(
                    'the result',
                    'handler',
                )]),
            );

        $queryBus = new MessengerQueryBus($messageBus);

        self::assertSame('the result', $queryBus->ask(new stdClass()));
    }
}
