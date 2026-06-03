<?php

declare(strict_types=1);

namespace Timeleads\EloquentClickHouse\Tests;

use ClickHouseDB\Client;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Timeleads\EloquentClickHouse\ClickHouseConnection;

final class ConnectionTest extends TestCase
{
    private function connection(): ClickHouseConnection
    {
        $client = (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();

        return new ClickHouseConnection($client, 'default', '', []);
    }

    /**
     * Pretend mode must NOT execute writes (it short-circuits before touching the client),
     * while still logging the query — otherwise migrate --pretend would mutate ClickHouse.
     */
    public function test_pretend_does_not_execute_writes(): void
    {
        $queries = $this->connection()->pretend(function (ClickHouseConnection $conn): void {
            $conn->insert('INSERT INTO events (id) VALUES (1)');
        });

        self::assertCount(1, $queries);
        self::assertSame('INSERT INTO events (id) VALUES (1)', $queries[0]['query']);
    }
}
