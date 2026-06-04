<?php

declare(strict_types=1);

namespace Timeleads\EloquentClickHouse\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Timeleads\EloquentClickHouse\ClickHouseConnection;
use Timeleads\EloquentClickHouse\ClickHouseConnector;

/**
 * Live schema-builder tests: the DDL compiled by SchemaGrammar must actually run on
 * ClickHouse and the introspection queries must read back what was created.
 */
final class SchemaTest extends TestCase
{
    private const TABLE = 'schema_test';

    private ClickHouseConnection $connection;

    protected function setUp(): void
    {
        if (! getenv('CLICKHOUSE_HOST')) {
            $this->markTestSkipped('CLICKHOUSE_HOST not set — integration environment unavailable.');
        }

        $config = [
            'host' => getenv('CLICKHOUSE_HOST'),
            'port' => (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            'database' => getenv('CLICKHOUSE_DATABASE') ?: 'default',
            'username' => getenv('CLICKHOUSE_USERNAME') ?: 'default',
            'password' => getenv('CLICKHOUSE_PASSWORD') ?: '',
        ];

        $client = (new ClickHouseConnector)->connect($config);
        $this->connection = new ClickHouseConnection($client, $config['database'], '', $config);

        $this->connection->getSchemaBuilder()->dropIfExists(self::TABLE);
        $this->connection->getSchemaBuilder()->dropIfExists(self::TABLE.'_renamed');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->getSchemaBuilder()->dropIfExists(self::TABLE);
            $this->connection->getSchemaBuilder()->dropIfExists(self::TABLE.'_renamed');
        }
    }

    public function test_create_has_table_columns_and_drop_round_trip(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        self::assertFalse($schema->hasTable(self::TABLE));

        $schema->create(self::TABLE, function (Blueprint $table): void {
            $table->uuid('id');
            $table->string('name')->default('none')->comment('display name');
            $table->unsignedBigInteger('hits')->default(0);
            $table->dateTime('created_at', 3)->nullable();
            $table->primary('id');
        });

        self::assertTrue($schema->hasTable(self::TABLE));
        self::assertTrue($schema->hasColumn(self::TABLE, 'name'));
        self::assertTrue($schema->hasColumns(self::TABLE, ['id', 'name', 'hits', 'created_at']));
        self::assertFalse($schema->hasColumn(self::TABLE, 'missing'));

        $columns = collect($schema->getColumns(self::TABLE))->keyBy('name');

        self::assertSame('UUID', $columns['id']['type']);
        self::assertSame('String', $columns['name']['type']);
        self::assertSame("'none'", $columns['name']['default']);
        self::assertSame('display name', $columns['name']['comment']);
        self::assertSame('UInt64', $columns['hits']['type']);
        self::assertTrue($columns['created_at']['nullable']);
        self::assertSame('DateTime64', $columns['created_at']['type_name']);

        // The driver must also be able to use the table it created.
        $this->connection->table(self::TABLE)->insert([
            'id' => '00000000-0000-0000-0000-000000000001',
            'name' => 'first',
        ]);

        $row = $this->connection->table(self::TABLE)->first();
        self::assertSame('first', $row['name']);
        self::assertSame('0', (string) $row['hits']);

        $tables = array_column($schema->getTables(), 'name');
        self::assertContains(self::TABLE, $tables);

        $schema->drop(self::TABLE);
        self::assertFalse($schema->hasTable(self::TABLE));
    }

    public function test_alter_add_change_drop_and_rename(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        $schema->create(self::TABLE, function (Blueprint $table): void {
            $table->string('name');
        });

        $schema->table(self::TABLE, function (Blueprint $table): void {
            $table->unsignedInteger('rank')->default(5)->after('name');
        });

        self::assertTrue($schema->hasColumn(self::TABLE, 'rank'));

        $schema->table(self::TABLE, function (Blueprint $table): void {
            $table->string('rank')->nullable()->change();
        });

        $columns = collect($schema->getColumns(self::TABLE))->keyBy('name');
        self::assertSame('Nullable(String)', $columns['rank']['type']);

        $schema->table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('rank');
        });

        self::assertFalse($schema->hasColumn(self::TABLE, 'rank'));

        $schema->rename(self::TABLE, self::TABLE.'_renamed');

        self::assertFalse($schema->hasTable(self::TABLE));
        self::assertTrue($schema->hasTable(self::TABLE.'_renamed'));
    }

    public function test_drop_all_tables_supports_migrate_fresh(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        $schema->create(self::TABLE, function (Blueprint $table): void {
            $table->string('name');
        });

        self::assertTrue($schema->hasTable(self::TABLE));

        $schema->dropAllTables();

        self::assertFalse($schema->hasTable(self::TABLE));
        self::assertSame([], $schema->getTables());
    }
}
