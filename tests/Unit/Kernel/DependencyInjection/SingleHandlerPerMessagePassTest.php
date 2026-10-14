<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel\DependencyInjection;

use App\Kernel\DependencyInjection\SingleHandlerPerMessagePass;
use App\Tests\Unit\Kernel\DependencyInjection\Fixtures\Application\DoSomething\AnotherDoSomethingHandler;
use App\Tests\Unit\Kernel\DependencyInjection\Fixtures\Application\DoSomething\DoSomething;
use App\Tests\Unit\Kernel\DependencyInjection\Fixtures\Application\DoSomething\DoSomethingHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SingleHandlerPerMessagePassTest extends TestCase
{
    private const string APPLICATION_NAMESPACE = 'App\\Tests\\Unit\\Kernel\\DependencyInjection\\Fixtures\\Application\\';

    public function test_it_accepts_a_message_with_exactly_one_handler(): void
    {
        $container = new ContainerBuilder();
        $container->register(DoSomething::class, DoSomething::class);
        $container->register(DoSomethingHandler::class, DoSomethingHandler::class)->addTag('messenger.message_handler');

        $this->expectNotToPerformAssertions();

        $this->pass()->process($container);
    }

    public function test_it_rejects_a_message_with_no_handler(): void
    {
        $container = new ContainerBuilder();
        $container->register(DoSomething::class, DoSomething::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Message "%s" has no handler', DoSomething::class));

        $this->pass()->process($container);
    }

    public function test_it_rejects_a_message_with_more_than_one_handler(): void
    {
        $container = new ContainerBuilder();
        $container->register(DoSomething::class, DoSomething::class);
        $container->register(DoSomethingHandler::class, DoSomethingHandler::class)->addTag('messenger.message_handler');
        $container
            ->register(AnotherDoSomethingHandler::class, AnotherDoSomethingHandler::class)
            ->addTag('messenger.message_handler');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Message "%s" has more than one handler', DoSomething::class));

        $this->pass()->process($container);
    }

    private function pass(): SingleHandlerPerMessagePass
    {
        return new SingleHandlerPerMessagePass(self::APPLICATION_NAMESPACE);
    }
}
