<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel\DependencyInjection\Fixtures\Application\DoSomething;

final class AnotherDoSomethingHandler
{
    public function __invoke(DoSomething $message): DoSomething
    {
        return $message;
    }
}
