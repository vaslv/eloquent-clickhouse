<?php

declare(strict_types=1);

namespace Vaslv\EloquentClickHouse\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Vaslv\EloquentClickHouse\ClickHouseConnection;
use Vaslv\EloquentClickHouse\ClickHouseConnector;

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

        $this->dropArtifacts();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->dropArtifacts();
        }
    }

    private function dropArtifacts(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        $schema->dropIfExists(self::TABLE);
        $schema->dropIfExists(self::TABLE.'_renamed');
        $this->connection->statement('DROP TABLE IF EXISTS p_'.self::TABLE);
        $this->connection->statement('DROP TABLE IF EXISTS '.self::TABLE.'_view');
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

    /**
     * system.tables reports physical names; dropAllTables must not re-apply the
     * connection prefix on top of them (it used to emit "p_p_evt" and skip everything).
     */
    public function test_drop_all_tables_honours_the_table_prefix(): void
    {
        $config = $this->connection->getConfig();
        $client = (new ClickHouseConnector)->connect($config);
        $prefixed = new ClickHouseConnection($client, $config['database'], 'p_', $config);

        $schema = $prefixed->getSchemaBuilder();
        $schema->create(self::TABLE, function (Blueprint $table): void {
            $table->string('name');
        });

        // Physical name carries the prefix.
        self::assertContains('p_'.self::TABLE, array_column($schema->getTables(), 'name'));

        $schema->dropAllTables();

        self::assertFalse($schema->hasTable(self::TABLE));
        self::assertNotContains('p_'.self::TABLE, array_column($schema->getTables(), 'name'));
    }

    public function test_enum_column_round_trips(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        $schema->create(self::TABLE, function (Blueprint $table): void {
            $table->enum('status', ['new', 'done']);
        });

        $this->connection->table(self::TABLE)->insert(['status' => 'done']);

        self::assertSame('done', $this->connection->table(self::TABLE)->value('status'));

        $columns = collect($schema->getColumns(self::TABLE))->keyBy('name');
        self::assertSame("Enum8('new' = 1, 'done' = 2)", $columns['status']['type']);
    }

    public function test_get_views_and_drop_all_views(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        $schema->create(self::TABLE, function (Blueprint $table): void {
            $table->string('name');
        });

        $this->connection->statement(
            'CREATE VIEW '.self::TABLE.'_view AS SELECT name FROM '.self::TABLE,
        );

        try {
            $views = $schema->getViews();

            self::assertContains(self::TABLE.'_view', array_column($views, 'name'));
            $view = collect($views)->firstWhere('name', self::TABLE.'_view');
            self::assertStringContainsString('SELECT', (string) $view['definition']);

            // dropAllTables must leave views alone...
            $schema->dropAllTables();
            self::assertContains(self::TABLE.'_view', array_column($schema->getViews(), 'name'));

            // ...and dropAllViews must remove them.
            $schema->dropAllViews();
            self::assertSame([], $schema->getViews());
        } finally {
            $this->connection->statement('DROP TABLE IF EXISTS '.self::TABLE.'_view');
        }
    }
}
