<?php

declare(strict_types=1);

namespace App\Kernel;

use MongoDB\Client;
use MongoDB\Collection;

/**
 * Builds the MongoDB collection the domain-event store appends to, by hand
 * (there is no framework bundle to do it via configuration). Connection details
 * come straight from the environment, matching `DocumentManagerFactory`.
 */
final class MongoCollectionFactory
{
    public static function domainEvents(): Collection
    {
        $client = new Client($_ENV['MONGO_URI'] ?? 'mongodb://mongodb:27017');

        return $client->selectCollection($_ENV['MONGO_DB'] ?? 'vending', 'domain_events');
    }
}
