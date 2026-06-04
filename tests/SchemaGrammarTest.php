<?php

declare(strict_types=1);

namespace Timeleads\EloquentClickHouse\Tests;

use ClickHouseDB\Client;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Timeleads\EloquentClickHouse\ClickHouseConnection;
use Timeleads\EloquentClickHouse\SchemaGrammar;

/**
 * Compiled-DDL assertions for the schema grammar. No database required: Blueprint::toSql()
 * never touches the wire.
 */
final class SchemaGrammarTest extends TestCase
{
    private function connection(array $config = []): ClickHouseConnection
    {
        $client = (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();

        $connection = new ClickHouseConnection($client, 'default', '', $config);
        $connection->useDefaultSchemaGrammar();

        return $connection;
    }

    private function grammar(): SchemaGrammar
    {
        return $this->connection()->getSchemaGrammar();
    }

    public function test_create_table_compiles_clickhouse_ddl(): void
    {
        $blueprint = new Blueprint($this->connection(), 'events');
        $blueprint->create();
        $blueprint->string('name');
        $blueprint->unsignedBigInteger('hits')->default(0);
        $blueprint->dateTime('created_at', 3)->nullable();

        self::assertSame(
            ['create table "events" ("name" String, "hits" UInt64 default 0, '
                .'"created_at" Nullable(DateTime64(3))) engine = MergeTree order by tuple()'],
            $blueprint->toSql(),
        );
    }

    public function test_create_uses_blueprint_engine_and_primary_key_as_sorting_key(): void
    {
        $blueprint = new Blueprint($this->connection(), 'events');
        $blueprint->create();
        $blueprint->engine('ReplacingMergeTree');
        $blueprint->uuid('id');
        $blueprint->date('day');
        $blueprint->primary(['day', 'id']);

        self::assertSame(
            ['create table "events" ("id" UUID, "day" Date) '
                .'engine = ReplacingMergeTree order by ("day", "id")'],
            $blueprint->toSql(),
        );
    }

    public function test_engine_with_inline_order_by_is_passed_through(): void
    {
        $blueprint = new Blueprint($this->connection(), 'events');
        $blueprint->create();
        $blueprint->engine('MergeTree ORDER BY (id)');
        $blueprint->uuid('id');

        $sql = $blueprint->toSql()[0];

        self::assertStringEndsWith('engine = MergeTree ORDER BY (id)', $sql);
    }

    public function test_non_merge_tree_engine_gets_no_sorting_key(): void
    {
        $blueprint = new Blueprint($this->connection(), 'events');
        $blueprint->create();
        $blueprint->engine('Memory');
        $blueprint->string('name');

        self::assertSame(['create table "events" ("name" String) engine = Memory'], $blueprint->toSql());
    }

    public function test_engine_falls_back_to_connection_config(): void
    {
        $blueprint = new Blueprint($this->connection(['engine' => 'TinyLog']), 'events');
        $blueprint->create();
        $blueprint->string('name');

        self::assertSame(['create table "events" ("name" String) engine = TinyLog'], $blueprint->toSql());
    }

    public function test_temporary_table_has_no_engine_clause(): void
    {
        $blueprint = new Blueprint($this->connection(), 'tmp');
        $blueprint->create();
        $blueprint->temporary();
        $blueprint->string('name');

        self::assertSame(['create temporary table "tmp" ("name" String)'], $blueprint->toSql());
    }

    public function test_auto_increment_id_throws(): void
    {
        $blueprint = new Blueprint($this->connection(), 'events');
        $blueprint->create();
        $blueprint->id();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('auto-incrementing');

        $blueprint->toSql();
    }

    public function test_nullable_column_in_sorting_key_throws(): void
    {
        $blueprint = new Blueprint($this->connection(), 'events');
        $blueprint->create();
        $blueprint->string('k')->nullable();
        $blueprint->primary('k');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nullable');

        $blueprint->toSql();
    }

    public function test_date_time_precision_above_nine_throws(): void
    {
        $blueprint = new Blueprint($this->connection(), 'events');
        $blueprint->create();
        $blueprint->dateTime('at', 12);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DateTime64');

        $blueprint->toSql();
    }

    public function test_time_column_throws(): void
    {
        $blueprint = new Blueprint($this->connection(), 'events');
        $blueprint->create();
        $blueprint->time('at');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql();
    }

    public function test_index_throws_instead_of_being_silently_skipped(): void
    {
        $blueprint = new Blueprint($this->connection(), 'events');
        $blueprint->index('name');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql();
    }

    public function test_add_column_compiles_with_modifiers(): void
    {
        $blueprint = new Blueprint($this->connection(), 'events');
        $blueprint->string('tag')->default("a'b")->comment('label')->after('name');

        self::assertSame(
            ['alter table "events" add column "tag" String default \'a\'\'b\' comment \'label\' after "name"'],
            $blueprint->toSql(),
        );
    }

    public function test_change_column_compiles_to_modify_column(): void
    {
        $blueprint = new Blueprint($this->connection(), 'events');
        $blueprint->string('tag')->nullable()->change();

        self::assertSame(
            ['alter table "events" modify column "tag" Nullable(String)'],
            $blueprint->toSql(),
        );
    }

    public function test_drop_column_compiles_per_column(): void
    {
        $blueprint = new Blueprint($this->connection(), 'events');
        $blueprint->dropColumn(['a', 'b']);

        self::assertSame(
            ['alter table "events" drop column "a", drop column "b"'],
            $blueprint->toSql(),
        );
    }

    public function test_drop_and_rename_table(): void
    {
        $drop = new Blueprint($this->connection(), 'events');
        $drop->drop();
        self::assertSame(['drop table "events"'], $drop->toSql());

        $dropIfExists = new Blueprint($this->connection(), 'events');
        $dropIfExists->dropIfExists();
        self::assertSame(['drop table if exists "events"'], $dropIfExists->toSql());

        $rename = new Blueprint($this->connection(), 'events');
        $rename->rename('archive');
        self::assertSame(['rename table "events" to "archive"'], $rename->toSql());
    }

    public function test_enum_compiles_with_escaped_values(): void
    {
        $blueprint = new Blueprint($this->connection(), 'events');
        $blueprint->enum('status', ['new', "o'k"]);

        $sql = $blueprint->toSql()[0];

        self::assertStringContainsString("Enum8('new', 'o''k')", $sql);
    }

    public function test_use_current_compiles_to_now(): void
    {
        $blueprint = new Blueprint($this->connection(), 'events');
        $blueprint->timestamp('created_at')->useCurrent();

        self::assertStringContainsString('"created_at" DateTime default now()', $blueprint->toSql()[0]);
    }

    public function test_table_exists_uses_system_tables_and_escapes(): void
    {
        $sql = $this->grammar()->compileTableExists(null, "ev'il");

        self::assertSame(
            "select exists (select 1 from system.tables where database = currentDatabase() and name = 'ev''il')",
            $sql,
        );

        self::assertStringContainsString(
            "database = 'analytics'",
            $this->grammar()->compileTableExists('analytics', 'events'),
        );
    }

    public function test_tables_and_columns_queries_target_system_tables(): void
    {
        $tables = $this->grammar()->compileTables(null);
        self::assertStringContainsString('from system.tables where database = currentDatabase()', $tables);

        $columns = $this->grammar()->compileColumns('analytics', 'events');
        self::assertStringContainsString('from system.columns', $columns);
        self::assertStringContainsString("database = 'analytics'", $columns);
        self::assertStringContainsString("table = 'events'", $columns);
    }
}
