<?php

declare(strict_types=1);

namespace App\Kernel\DependencyInjection;

use RuntimeException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails container compilation unless every command/query is handled by exactly
 * one handler.
 *
 * Handlers are discovered from the `messenger.message_handler` tag the
 * `#[CommandHandler]`/`#[QueryHandler]` attributes add. Tags bound to another
 * bus — the `domain_event.bus` subscribers marked `#[DomainEventSubscriber]` —
 * are ignored on purpose: an event may have any number of subscribers, while a
 * command or query must have exactly one handler. The messages that *should*
 * be handled come from the use-case classes themselves, so a use case with no
 * handler at all fails too — something the Messenger handler locator alone
 * cannot see.
 */
final class SingleHandlerPerMessagePass implements CompilerPassInterface
{
    private const string HANDLER_TAG = 'messenger.message_handler';

    /**
     * The buses whose messages must have exactly one handler. A tag without a
     * `bus` attribute is registered on every bus by Messenger, so it counts.
     *
     * @var list<string>
     */
    private const array SINGLE_HANDLER_BUSES = ['command.bus', 'query.bus'];

    /**
     * @param string $applicationNamespace prefix of the use-case classes to inspect
     */
    public function __construct(
        private readonly string $applicationNamespace = 'App\\VendingMachine\\Application\\',
    ) {}

    public function process(ContainerBuilder $container): void
    {
        $handlersByMessage = $this->handlersByMessage($container);

        $this->assertAtMostOneHandlerPerMessage($handlersByMessage);
        $this->assertEveryUseCaseIsHandled($container, $handlersByMessage);
    }

    /**
     * @return array<string, list<string>>
     */
    private function handlersByMessage(ContainerBuilder $container): array
    {
        $handlersByMessage = [];

        /** @var array<string, list<array<string, mixed>>> $taggedServices */
        $taggedServices = $container->findTaggedServiceIds(self::HANDLER_TAG);

        foreach ($taggedServices as $serviceId => $tags) {
            foreach ($tags as $tag) {
                /** @var string|null $bus */
                $bus = $tag['bus'] ?? null;

                if ($bus !== null && !in_array($bus, self::SINGLE_HANDLER_BUSES, strict: true)) {
                    continue;
                }

                foreach (HandlerMessages::of($container, $serviceId) as $message) {
                    $handlersByMessage[$message][] = $serviceId;
                }
            }
        }

        return $handlersByMessage;
    }

    /**
     * @param array<string, list<string>> $handlersByMessage
     */
    private function assertAtMostOneHandlerPerMessage(array $handlersByMessage): void
    {
        foreach ($handlersByMessage as $message => $serviceIds) {
            if (count($serviceIds) <= 1) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Message "%s" has more than one handler ("%s"); a command or query must have exactly one.',
                $message,
                implode('", "', $serviceIds),
            ));
        }
    }

    /**
     * @param array<string, list<string>> $handlersByMessage
     */
    private function assertEveryUseCaseIsHandled(ContainerBuilder $container, array $handlersByMessage): void
    {
        foreach (UseCaseMessages::in($container, $this->applicationNamespace) as $message) {
            if (array_key_exists($message, $handlersByMessage)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Message "%s" has no handler; a command or query must have exactly one.',
                $message,
            ));
        }
    }
}
