<?php

namespace Timeleads\EloquentClickHouse;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use RuntimeException;
use Timeleads\EloquentClickHouse\Concerns\EscapesClickHouseStrings;

class QueryGrammar extends PostgresGrammar
{
    use EscapesClickHouseStrings;

    protected function whereDate(Builder $query, $where): string
    {
        return $this->dateBasedWhere('toDate', $query, $where);
    }

    protected function whereTime(Builder $query, $where): string
    {
        return 'formatDateTime('.$this->wrap($where['column']).", '%H:%M:%S') "
            .$where['operator'].' '.$this->parameter($where['value']);
    }

    protected function dateBasedWhere($type, Builder $query, $where): string
    {
        // Map Laravel's date-part keywords to ClickHouse functions. whereDate passes the
        // function name ('toDate') directly. parameter() already quotes the value, so it must
        // not be quoted again here.
        $function = match ($type) {
            'year' => 'toYear',
            'month' => 'toMonth',
            'day' => 'toDayOfMonth',
            default => $type,
        };

        return $function.'('.$this->wrap($where['column']).') '
            .$where['operator'].' '.$this->parameter($where['value']);
    }

    public function parameter($value): float|int|string|Expression
    {
        if ($this->isExpression($value)) {
            return $this->getValue($value);
        }

        if (is_null($value)) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \DateTimeInterface) {
            return "'".$value->format($this->getDateFormat())."'";
        }

        if (is_string($value)) {
            return $this->escapeClickHouseString($value);
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return $this->escapeClickHouseString((string) $value);
    }

    /**
     * ClickHouse has no standard UPDATE; compile to an ALTER TABLE mutation. Overridden
     * at this level (not compileUpdateWithoutJoins) because PostgresGrammar diverts
     * queries with a limit into a ctid-subquery form that ClickHouse cannot run. A
     * "limit" has no mutation equivalent and is ignored — Laravel's updateOrInsert()
     * adds a defensive limit(1) to updates targeting a unique row.
     */
    public function compileUpdate(Builder $query, array $values): string
    {
        if (isset($query->joins)) {
            throw new RuntimeException('ClickHouse does not support update statements with joins.');
        }

        $query = $this->unqualifyMutationWheres($query);

        $table = $this->wrapTable($query->from);
        $columns = $this->compileUpdateColumns($query, $values);
        $where = $this->compileWheres($query);

        // ALTER TABLE ... UPDATE requires a WHERE clause.
        return trim("alter table {$table} update {$columns} ".($where !== '' ? $where : 'where 1'));
    }

    /**
     * Compile delete to an ALTER TABLE mutation. An unconditional delete maps to
     * TRUNCATE, which is synchronous and far cheaper than a full-table mutation.
     */
    public function compileDelete(Builder $query): string
    {
        if (isset($query->joins)) {
            throw new RuntimeException('ClickHouse does not support delete statements with joins.');
        }

        if (isset($query->limit)) {
            throw new RuntimeException('ClickHouse mutations do not support delete with a limit.');
        }

        $query = $this->unqualifyMutationWheres($query);

        $table = $this->wrapTable($query->from);
        $where = $this->compileWheres($query);

        if ($where === '') {
            return "truncate table {$table}";
        }

        return trim("alter table {$table} delete {$where}");
    }

    /**
     * ClickHouse mutation predicates only accept bare column names of the target table,
     * but Laravel qualifies some of them (Builder::delete($id) injects "table.id").
     * Mutations never join, so any qualifier can only refer to the target table itself
     * and is safe to strip. Operates on clones; the caller's builder stays untouched.
     */
    private function unqualifyMutationWheres(Builder $query): Builder
    {
        $query = clone $query;
        $query->wheres = $this->unqualifyWheres($query->wheres);

        return $query;
    }

    private function unqualifyWheres(array $wheres): array
    {
        return array_map(function (array $where) {
            foreach (['column', 'first', 'second'] as $key) {
                if (isset($where[$key]) && is_string($where[$key]) && str_contains($where[$key], '.')) {
                    $where[$key] = substr($where[$key], strrpos($where[$key], '.') + 1);
                }
            }

            // Recurse into grouped wheres only; sub-select queries (InSub, Exists, ...)
            // reference other tables and must keep their qualification.
            if (($where['type'] ?? null) === 'Nested' && isset($where['query'])) {
                $where['query'] = clone $where['query'];
                $where['query']->wheres = $this->unqualifyWheres($where['query']->wheres);
            }

            return $where;
        }, $wheres);
    }

    /**
     * Inherited from PostgresGrammar this would emit "insert ... returning id", which
     * ClickHouse rejects — reachable from any Eloquent model that keeps the default
     * $incrementing = true.
     */
    public function compileInsertGetId(Builder $query, $values, $sequence): string
    {
        throw new RuntimeException(
            'ClickHouse has no auto-increment or RETURNING clause; set public $incrementing = false '
            .'on the model and provide the key explicitly (e.g. a UUID), or use insert().',
        );
    }

    /**
     * Inherited from PostgresGrammar this would emit "insert ... on conflict do update",
     * which ClickHouse rejects.
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update): string
    {
        throw new RuntimeException(
            'ClickHouse does not support upsert (ON CONFLICT); use insert() and deduplicate '
            .'with a ReplacingMergeTree engine, or run an explicit ALTER TABLE ... UPDATE mutation.',
        );
    }

    /**
     * The inherited Postgres form ("truncate ... restart identity cascade") is invalid
     * in ClickHouse.
     */
    public function compileTruncate(Builder $query): array
    {
        return ['truncate table '.$this->wrapTable($query->from) => []];
    }

    /**
     * Inline bindings into raw SQL using ClickHouse-safe quoting. Mirrors the framework's
     * literal-aware scanner but escapes through parameter() instead of Connection::escape(),
     * which would call PDO::quote() on the non-PDO smi2 client.
     */
    public function substituteBindingsIntoRawSql($sql, $bindings): string
    {
        $query = '';
        $bindingIndex = 0;
        $isStringLiteral = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $nextChar = $sql[$i + 1] ?? null;

            if (in_array($char.$nextChar, ["\\'", "''", '??'], true)) {
                $query .= $char.$nextChar;
                $i++;
            } elseif ($char === "'") {
                $query .= $char;
                $isStringLiteral = ! $isStringLiteral;
            } elseif ($char === '?' && ! $isStringLiteral) {
                $query .= (string) $this->parameter($bindings[$bindingIndex++] ?? '?');
            } else {
                $query .= $char;
            }
        }

        return $query;
    }
}
