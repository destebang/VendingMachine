<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Console;

use App\Shared\Application\IntegrationEvent\IntegrationEvent;
use App\Shared\Application\IntegrationEvent\IntegrationEventBus;
use App\VendingMachine\Application\IntegrationEventTranslator\VendingMachineIntegrationEventTranslator;
use App\VendingMachine\Infrastructure\Persistence\Mongo\VendingMachineDocument;
use DateTimeImmutable;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The outbox relay: polls the write-side documents for pending outbox entries
 * and publishes them as integration events, then removes them from the
 * outbox.
 *
 * The entries of one aggregate are published in aggregate-version order.
 * Publishing waits for the broker's confirm, and an entry is only `$pull`ed
 * once it is durably held, so delivery is at-least-once: a publish failure
 * (or a crash between publish and removal) leaves the entry pending and it is
 * retried on the next poll — consumers deduplicate on the envelope `id`.
 * Entries with no published counterpart are removed without being published:
 * the published language exposes only what other contexts may know.
 *
 * Runs forever, polling about every 500 ms (`--poll-interval-ms`); `--once`
 * runs a single pass, which is how the integration tests drive it.
 */
#[AsCommand(
    name: 'app:outbox:relay',
    description: 'Publishes pending outbox events as integration events, polling the write-side documents.',
)]
final class OutboxRelayCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly IntegrationEventBus $integrationEventBus,
        private readonly VendingMachineIntegrationEventTranslator $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'poll-interval-ms',
            null,
            InputOption::VALUE_REQUIRED,
            'Wait between outbox polls, in milliseconds.',
            '500',
        )->addOption('once', null, InputOption::VALUE_NONE, 'Run a single poll pass and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Cheap insurance for a long-running process: the multikey index that
        // makes finding documents with pending events cheap is ensured once
        // per relay start.
        $this->documentManager->getSchemaManager()->ensureDocumentIndexes(VendingMachineDocument::class);

        $pollIntervalMs = max(0, (int) $input->getOption('poll-interval-ms'));
        $once = $input->getOption('once') === true;

        do {
            $this->relayPendingEvents($output);

            if ($once) {
                return Command::SUCCESS;
            }

            usleep($pollIntervalMs * 1000);
        } while (true);
    }

    private function relayPendingEvents(OutputInterface $output): void
    {
        $collection = $this->documentManager->getDocumentCollection(VendingMachineDocument::class);

        // The batch is materialised before anything is pulled: a MongoDB
        // cursor may return a document twice when that document is updated
        // mid-scan, which would republish entries the pull already removed.
        /** @var list<array{_id: string, pendingEvents: list<array{eventId: string, type: string, payload: array<string, mixed>, aggregateVersion: int, occurredAt: string}>}> $documents */
        $documents = iterator_to_array($collection->find(['pendingEvents.eventId' => [
            '$exists' => true,
        ]]), preserve_keys: false);

        foreach ($documents as $document) {
            $entries = $document['pendingEvents'];
            usort(
                $entries,
                static fn(array $left, array $right): int => $left['aggregateVersion'] <=> $right['aggregateVersion'],
            );

            foreach ($entries as $entry) {
                if (!$this->relayEntry($entry, $output)) {
                    // A failed publish stops this aggregate at the current
                    // entry: later events may only be published in version
                    // order, so they wait for the next poll.
                    continue 2;
                }

                $collection->updateOne(['_id' => $document['_id']], [
                    '$pull' => ['pendingEvents' => ['eventId' => $entry['eventId']]],
                ]);
            }
        }
    }

    /**
     * Publishes one outbox entry as an integration event. Returns whether the
     * entry may be removed from the outbox: published, or deliberately
     * dropped because the event is not part of the published language.
     *
     * @param array{eventId: string, type: string, payload: array<string, mixed>, aggregateVersion: int, occurredAt: string} $entry
     */
    private function relayEntry(array $entry, OutputInterface $output): bool
    {
        $integrationEvent = $this->translator->translate(
            $entry['type'],
            $entry['payload'],
            new DateTimeImmutable($entry['occurredAt']),
        );

        if (!$integrationEvent instanceof IntegrationEvent) {
            // Not part of the published language: dropped without being
            // published. The normal case, so it only shows in verbose mode.
            $output->writeln(
                sprintf('Dropped %s (%s): not part of the published language.', $entry['eventId'], $entry['type']),
                OutputInterface::VERBOSITY_VERBOSE,
            );

            return true;
        }

        try {
            $this->integrationEventBus->publish($integrationEvent);
        } catch (Throwable $exception) {
            $output->writeln(sprintf(
                'Publishing %s (%s) failed, it stays pending for the next poll: %s',
                $entry['eventId'],
                $integrationEvent->type(),
                $exception->getMessage(),
            ));

            return false;
        }

        $output->writeln(sprintf('Published %s (%s).', $entry['eventId'], $integrationEvent->type()));

        return true;
    }
}
