<?php

declare(strict_types=1);

namespace Timeleads\EloquentClickHouse\Tests;

use ClickHouseDB\Client;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\Grammar as BaseSchemaGrammar;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Timeleads\EloquentClickHouse\ClickHouseConnection;
use Timeleads\EloquentClickHouse\QueryGrammar;
use Timeleads\EloquentClickHouse\SchemaGrammar;
use Timeleads\EloquentClickHouse\ServiceProvider;

final class CompatibilityTest extends TestCase
{
    public function test_clickhouse_connection_select_signature_matches_parent(): void
    {
        $this->assertMethodSignatureIsCompatible(
            Connection::class,
            'select',
            ClickHouseConnection::class,
            'select',
        );
    }

    public function test_query_grammar_overrides_match_parent_signatures(): void
    {
        $this->assertMethodSignatureIsCompatible(
            PostgresGrammar::class,
            'whereDate',
            QueryGrammar::class,
            'whereDate',
        );

        $this->assertMethodSignatureIsCompatible(
            PostgresGrammar::class,
            'dateBasedWhere',
            QueryGrammar::class,
            'dateBasedWhere',
        );

        $this->assertMethodSignatureIsCompatible(
            Grammar::class,
            'parameter',
            QueryGrammar::class,
            'parameter',
        );
    }

    public function test_service_provider_registers_clickhouse_driver(): void
    {
        $app = new Container;
        $factory = new class
        {
            public array $extensions = [];

            public function extend(string $driver, callable $callback): void
            {
                $this->extensions[$driver] = $callback;
            }
        };

        $app->instance('db', $factory);

        /** @noinspection PhpParamsInspection */
        $provider = new ServiceProvider($app);
        $provider->register();
        $provider->boot();

        self::assertTrue($app->bound('db.connector.clickhouse'));
        self::assertArrayHasKey('clickhouse', $factory->extensions);

        // The DatabaseManager extension is the single registration path; the
        // Connection::resolverFor() hook must stay unregistered (it would lose to the
        // extension anyway and would receive a PDO instead of the smi2 client).
        self::assertNull(Connection::getResolver('clickhouse'));

        $client = (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();
        $app->instance('db.connector.clickhouse', new class($client)
        {
            public function __construct(private readonly Client $client) {}

            public function connect(array $config): Client
            {
                return $this->client;
            }
        });

        $connection = $factory->extensions['clickhouse'](['database' => 'default', 'prefix' => ''], 'clickhouse');
        $connection->useDefaultSchemaGrammar();

        self::assertInstanceOf(ClickHouseConnection::class, $connection);
        self::assertSame('clickhouse', $connection->getName());
        self::assertInstanceOf(QueryGrammar::class, $connection->getQueryGrammar());
        self::assertInstanceOf(SchemaGrammar::class, $connection->getSchemaGrammar());
    }

    private function assertMethodSignatureIsCompatible(
        string $parentClass,
        string $parentMethod,
        string $childClass,
        string $childMethod
    ): void {
        $parent = new ReflectionMethod($parentClass, $parentMethod);
        $child = new ReflectionMethod($childClass, $childMethod);

        self::assertGreaterThanOrEqual($parent->getNumberOfParameters(), $child->getNumberOfParameters());
        self::assertSame($parent->getNumberOfRequiredParameters(), $child->getNumberOfRequiredParameters());

        $parentParameters = $parent->getParameters();
        $childParameters = $child->getParameters();

        foreach ($parentParameters as $index => $parentParameter) {
            $childParameter = $childParameters[$index];

            self::assertSame($parentParameter->getName(), $childParameter->getName());
            self::assertSame($parentParameter->isOptional(), $childParameter->isOptional());
            self::assertSame($parentParameter->isVariadic(), $childParameter->isVariadic());
            self::assertSame($parentParameter->isPassedByReference(), $childParameter->isPassedByReference());
            self::assertSame(
                $this->normalizeType($parentParameter->getType()),
                $this->normalizeType($childParameter->getType()),
            );

            if ($parentParameter->isDefaultValueAvailable()) {
                self::assertTrue($childParameter->isDefaultValueAvailable());
                self::assertSame($parentParameter->getDefaultValue(), $childParameter->getDefaultValue());
            }
        }

        foreach (array_slice($childParameters, count($parentParameters)) as $childParameter) {
            self::assertTrue($childParameter->isOptional());
        }
    }

    private function normalizeType(?ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            return ($type->allowsNull() ? '?' : '').$type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            return implode(
                '|',
                array_map(
                    static fn (ReflectionNamedType|ReflectionUnionType $inner): string => $inner instanceof ReflectionNamedType
                        ? $inner->getName()
                        : (string) $inner,
                    $type->getTypes(),
                ),
            );
        }

        return (string) $type;
    }
}
