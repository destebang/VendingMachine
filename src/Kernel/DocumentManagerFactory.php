<?php

declare(strict_types=1);

namespace App\Kernel;

use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\Driver\AttributeDriver;
use MongoDB\Client;

/**
 * Builds the Doctrine ODM `DocumentManager` by hand, since there is no
 * framework bundle to do it via configuration. Connection details come
 * straight from the environment (see docker-compose.yml).
 */
final class DocumentManagerFactory
{
    public static function create(): DocumentManager
    {
        $cacheDir = sys_get_temp_dir() . '/vending-machine/doctrine-odm';

        $configuration = new Configuration();
        // Native lazy objects (PHP 8.4+) replace generated proxy classes, so the
        // proxy directory/namespace settings are no longer needed.
        $configuration->setUseNativeLazyObject(true);
        $configuration->setHydratorDir($cacheDir . '/hydrators');
        $configuration->setHydratorNamespace('App\\Infrastructure\\Doctrine\\Hydrators');
        $configuration->setDefaultDB($_ENV['MONGO_DB'] ?? 'vending');
        $configuration->setMetadataDriverImpl(new AttributeDriver([
            dirname(__DIR__) . '/VendingMachine/Infrastructure/Persistence/Mongo',
        ]));

        $client = new Client($_ENV['MONGO_URI'] ?? 'mongodb://mongodb:27017');

        return DocumentManager::create($client, $configuration);
    }
}
