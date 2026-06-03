<?php

declare(strict_types=1);

namespace Timeleads\EloquentClickHouse\Tests;

use ClickHouseDB\Client;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Timeleads\EloquentClickHouse\ClickHouseConnection;

/**
 * Compiled-SQL assertions for the query grammar. No database is required: the connection is
 * built with an unconstructed client and only toSql() (which never touches the wire) is used.
 */
final class QueryGrammarTest extends TestCase
{
    private function connection(): ClickHouseConnection
    {
        $client = (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();

        return new ClickHouseConnection($client, 'default', '', []);
    }

    public function test_where_date_quotes_value_once(): void
    {
        $sql = $this->connection()->table('events')
            ->whereDate('created_at', '2026-06-03')
            ->toSql();

        self::assertSame(
            'select * from "events" where toDate("created_at") = \'2026-06-03\'',
            $sql,
        );
    }

    public function test_where_year_uses_clickhouse_function(): void
    {
        $sql = $this->connection()->table('events')->whereYear('created_at', 2026)->toSql();

        self::assertStringContainsString('toYear("created_at")', $sql);
        self::assertStringNotContainsString("''", $sql);
    }

    public function test_where_month_uses_clickhouse_function(): void
    {
        $sql = $this->connection()->table('events')->whereMonth('created_at', 6)->toSql();

        self::assertStringContainsString('toMonth("created_at")', $sql);
        self::assertStringNotContainsString("''", $sql);
    }

    public function test_where_day_uses_clickhouse_function(): void
    {
        $sql = $this->connection()->table('events')->whereDay('created_at', 15)->toSql();

        self::assertStringContainsString('toDayOfMonth("created_at")', $sql);
        self::assertStringNotContainsString("''", $sql);
    }

    public function test_where_time_is_not_postgres_cast(): void
    {
        $sql = $this->connection()->table('events')->whereTime('created_at', '12:00:00')->toSql();

        self::assertStringNotContainsString('::time', $sql);
        self::assertStringContainsString('formatDateTime("created_at"', $sql);
    }
}
