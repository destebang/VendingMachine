<?php

declare(strict_types=1);

namespace App\Tests\Unit\AsyncApi;

use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Uri;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolves the `$ref`s of the AsyncAPI specs, which live as YAML files on
 * disk. Opis' own file resolver only reads JSON, so this maps a schema URI
 * back to the project directory and decodes the YAML into the plain objects
 * the validator expects.
 *
 * The specs are addressed by the absolute base `https://vending-machine/`, so
 * a `$ref` of `./envelope.yaml` inside a schema resolves to a URI whose path
 * is the schema's path under the project root.
 */
final class YamlSchemaResolver extends SchemaResolver
{
    public function __construct(
        private readonly string $rootDir,
        private readonly string $host = 'vending-machine',
    ) {}

    public function resolve(Uri $uri): mixed
    {
        if ((string) $uri->host() !== $this->host) {
            return null;
        }

        $path = $uri->path();
        if ($path === null) {
            return null;
        }

        $file = $this->rootDir . $path;
        if (!is_file($file)) {
            return null;
        }

        // YAML decodes to PHP arrays and scalars; a JSON round-trip turns the
        // maps into the plain objects the validator expects.
        return json_decode(
            json_encode(Yaml::parseFile($file), JSON_THROW_ON_ERROR),
            associative: false,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
