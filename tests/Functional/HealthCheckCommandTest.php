<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel\ContainerFactory;
use App\VendingMachine\Infrastructure\Console\HealthCheckCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class HealthCheckCommandTest extends TestCase
{
    public function test_the_cli_health_check_reports_mongo_and_redis_as_reachable(): void
    {
        $container = ContainerFactory::create();

        $command = $container->get(HealthCheckCommand::class);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($payload['mongo']);
        self::assertTrue($payload['redis']);
        self::assertTrue($payload['healthy']);
    }
}
