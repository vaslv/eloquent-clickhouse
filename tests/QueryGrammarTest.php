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

    public function test_parameter_escapes_backslash(): void
    {
        $grammar = $this->connection()->getQueryGrammar();

        self::assertSame("'\\\\'", $grammar->parameter('\\'));
    }

    public function test_parameter_doubles_single_quote(): void
    {
        $grammar = $this->connection()->getQueryGrammar();

        self::assertSame("'O''Brien'", $grammar->parameter("O'Brien"));
    }

    public function test_parameter_escapes_date_time_format_output(): void
    {
        $grammar = $this->connection()->getQueryGrammar();

        self::assertSame(
            "'2026-06-04 12:00:00'",
            $grammar->parameter(new \DateTimeImmutable('2026-06-04 12:00:00')),
        );

        // A DateTime subclass may override format(); its output must not break the literal.
        $evil = new class('now') extends \DateTime
        {
            public function format(string $format): string
            {
                return "2020-01-01' OR 1=1 -- ";
            }
        };

        self::assertSame("'2020-01-01'' OR 1=1 -- '", $grammar->parameter($evil));
    }

    public function test_substitute_bindings_inlines_and_escapes(): void
    {
        $grammar = $this->connection()->getQueryGrammar();

        self::assertSame('select 5', $grammar->substituteBindingsIntoRawSql('select ?', [5]));
        self::assertSame("select 'O''Brien'", $grammar->substituteBindingsIntoRawSql('select ?', ["O'Brien"]));
    }

    public function test_parameter_compiles_lists_to_array_literals(): void
    {
        $grammar = $this->connection()->getQueryGrammar();

        self::assertSame("['a', 'o''k', 1]", $grammar->parameter(['a', "o'k", 1]));
        self::assertSame('[]', $grammar->parameter([]));
        self::assertSame('[[1, 2], [3]]', $grammar->parameter([[1, 2], [3]]));
        self::assertSame("[NULL, 'x']", $grammar->parameter([null, 'x']));
    }

    public function test_parameter_compiles_associative_arrays_to_maps(): void
    {
        $grammar = $this->connection()->getQueryGrammar();

        self::assertSame(
            "map('region', 'eu', 'tier', 2)",
            $grammar->parameter(['region' => 'eu', 'tier' => 2]),
        );

        // Keys are escaped like any other value.
        self::assertSame(
            "map('o''k', 'v')",
            $grammar->parameter(["o'k" => 'v']),
        );
    }

    public function test_array_bindings_inline_as_array_literals(): void
    {
        $grammar = $this->connection()->getQueryGrammar();

        self::assertSame(
            "select ['a', 'b']",
            $grammar->substituteBindingsIntoRawSql('select ?', [['a', 'b']]),
        );
    }

    public function test_where_against_an_array_column_compiles_an_array_literal(): void
    {
        $sql = $this->connection()->table('events')->where('tags', ['a', 'b'])->toSql();

        self::assertSame('select * from "events" where "tags" = [\'a\', \'b\']', $sql);
    }

    public function test_update_with_array_value_compiles_an_array_literal(): void
    {
        $connection = $this->connection();
        $query = $connection->table('events')->where('id', 1);

        $sql = $connection->getQueryGrammar()->compileUpdate($query, ['tags' => ['x', 'y']]);

        self::assertSame('alter table "events" update "tags" = [\'x\', \'y\'] where "id" = 1', $sql);
    }

    public function test_update_compiles_to_alter_table_mutation(): void
    {
        $connection = $this->connection();
        $query = $connection->table('events')->where('id', 1);

        $sql = $connection->getQueryGrammar()->compileUpdate($query, ['name' => "O'Brien"]);

        self::assertSame('alter table "events" update "name" = \'O\'\'Brien\' where "id" = 1', $sql);
    }

    public function test_update_without_where_targets_all_rows(): void
    {
        $connection = $this->connection();
        $query = $connection->table('events');

        $sql = $connection->getQueryGrammar()->compileUpdate($query, ['name' => 'x']);

        self::assertSame('alter table "events" update "name" = \'x\' where 1', $sql);
    }

    public function test_update_ignores_the_defensive_update_or_insert_limit(): void
    {
        $connection = $this->connection();
        $query = $connection->table('events')->where('id', 1)->limit(1);

        $sql = $connection->getQueryGrammar()->compileUpdate($query, ['name' => 'x']);

        self::assertStringNotContainsString('limit', $sql);
        self::assertStringNotContainsString('ctid', $sql);
    }

    public function test_update_with_join_throws(): void
    {
        $connection = $this->connection();
        $query = $connection->table('events')->join('users', 'users.id', '=', 'events.user_id');

        $this->expectException(\RuntimeException::class);

        $connection->getQueryGrammar()->compileUpdate($query, ['name' => 'x']);
    }

    public function test_delete_compiles_to_alter_table_mutation(): void
    {
        $connection = $this->connection();
        $query = $connection->table('events')->where('id', 1);

        $sql = $connection->getQueryGrammar()->compileDelete($query);

        self::assertSame('alter table "events" delete where "id" = 1', $sql);
    }

    public function test_delete_without_where_compiles_to_truncate(): void
    {
        $connection = $this->connection();
        $query = $connection->table('events');

        self::assertSame('truncate table "events"', $connection->getQueryGrammar()->compileDelete($query));
    }

    public function test_delete_with_limit_throws(): void
    {
        $connection = $this->connection();
        $query = $connection->table('events')->where('id', 1)->limit(5);

        $this->expectException(\RuntimeException::class);

        $connection->getQueryGrammar()->compileDelete($query);
    }

    public function test_truncate_is_not_the_postgres_form(): void
    {
        $connection = $this->connection();
        $query = $connection->table('events');

        $statements = $connection->getQueryGrammar()->compileTruncate($query);

        self::assertSame(['truncate table "events"' => []], $statements);
    }

    /**
     * Builder::delete($id) qualifies the key as "table.id", but ClickHouse mutation
     * predicates only accept bare column names.
     */
    public function test_mutations_strip_table_qualified_where_columns(): void
    {
        $connection = $this->connection();
        $query = $connection->table('events')->where('events.id', 9);

        self::assertSame(
            'alter table "events" delete where "id" = 9',
            $connection->getQueryGrammar()->compileDelete($query),
        );
    }

    public function test_mutations_strip_qualification_inside_nested_wheres(): void
    {
        $connection = $this->connection();
        $query = $connection->table('events')->where(function ($q): void {
            $q->where('events.a', 1)->orWhere('events.b', 2);
        });

        $sql = $connection->getQueryGrammar()->compileUpdate($query, ['name' => 'x']);

        self::assertStringContainsString('where ("a" = 1 or "b" = 2)', $sql);
        self::assertStringNotContainsString('"events"."a"', $sql);
    }

    public function test_mutations_keep_subquery_columns_qualified(): void
    {
        $connection = $this->connection();
        $query = $connection->table('events')->whereIn('id', function ($q): void {
            $q->from('other')->select('id')->where('other.kind', 'x');
        });

        $sql = $connection->getQueryGrammar()->compileDelete($query);

        self::assertStringContainsString('"other"."kind"', $sql);
    }

    public function test_unqualifying_does_not_mutate_the_callers_builder(): void
    {
        $connection = $this->connection();
        $query = $connection->table('events')->where('events.id', 9);

        $connection->getQueryGrammar()->compileDelete($query);

        self::assertSame('events.id', $query->wheres[0]['column']);
    }

    public function test_insert_get_id_throws_instead_of_emitting_returning(): void
    {
        $connection = $this->connection();
        $query = $connection->table('events');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('auto-increment');

        $connection->getQueryGrammar()->compileInsertGetId($query, ['name' => 'x'], 'id');
    }

    public function test_upsert_throws_instead_of_emitting_on_conflict(): void
    {
        $connection = $this->connection();
        $query = $connection->table('events');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('upsert');

        $connection->getQueryGrammar()->compileUpsert($query, [['id' => 1]], ['id'], ['name']);
    }
}
