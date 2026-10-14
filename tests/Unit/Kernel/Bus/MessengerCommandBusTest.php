<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel\Bus;

use App\Kernel\Bus\MessengerCommandBus;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class MessengerCommandBusTest extends TestCase
{
    public function test_it_returns_the_result_of_the_dispatched_command(): void
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

        $commandBus = new MessengerCommandBus($messageBus);

        self::assertSame('the result', $commandBus->handle(new stdClass()));
    }

    public function test_it_unwraps_the_domain_exception_messenger_wraps(): void
    {
        $domainException = new RuntimeException('the domain rejected the command');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new HandlerFailedException(new Envelope(new stdClass()), [
                'handler' => $domainException,
            ]));

        $commandBus = new MessengerCommandBus($messageBus);

        $this->expectExceptionObject($domainException);

        $commandBus->handle(new stdClass());
    }
}
