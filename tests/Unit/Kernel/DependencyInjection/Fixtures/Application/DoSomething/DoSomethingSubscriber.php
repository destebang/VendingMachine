<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel\DependencyInjection\Fixtures\Application\DoSomething;

/**
 * A domain-event-style subscriber: reacts to the message through a named
 * method instead of `__invoke()`, mirroring the read-model projector. It
 * deliberately has no `__invoke()`, so the compiler pass must ignore it
 * without ever reflecting on it as a handler.
 */
final class DoSomethingSubscriber
{
    public function onDoSomething(DoSomething $event): void {}
}
