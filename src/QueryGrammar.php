<?php

namespace Vaslv\EloquentClickHouse;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use RuntimeException;
use Vaslv\EloquentClickHouse\Concerns\EscapesClickHouseStrings;

class QueryGrammar extends PostgresGrammar
{
    use EscapesClickHouseStrings;

    /**
     * Operators ClickHouse understands. Replaces the inherited Postgres extras
     * (@>, ?|, is distinct from, ...) which compile to invalid ClickHouse SQL —
     * and whose bare '?' would additionally collide with binding substitution.
     * (The query Builder keeps its own permissive default list on top of this.)
     *
     * @var string[]
     */
    protected $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'like', 'not like', 'ilike', 'not ilike',
    ];

    /**
     * @param  array<string, mixed>  $where
     */
    protected function whereDate(Builder $query, $where): string
    {
        return $this->dateBasedWhere('toDate', $query, $where);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    protected function whereTime(Builder $query, $where): string
    {
        return 'formatDateTime('.$this->wrap($where['column']).", '%H:%M:%S') "
            .$where['operator'].' '.$this->parameter($where['value']);
    }

    /**
     * @param  array<string, mixed>  $where
     */
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
            // Escape the formatted output too: a DateTime subclass can override
            // format() and return literal-breaking text. Idempotent for real dates.
            return $this->escapeClickHouseString($value->format($this->getDateFormat()));
        }

        if (is_array($value)) {
            return $this->compileArrayValue($value);
        }

        if (is_string($value)) {
            return $this->escapeClickHouseString($value);
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_nan($value)) {
                return 'nan';
            }

            if (is_infinite($value)) {
                return $value > 0 ? 'inf' : '-inf';
            }

            // (string) casts a float with the `precision` ini (default 14), which
            // silently rounds Float64 values on the way into SQL. json_encode uses
            // serialize_precision (-1: shortest round-trip), preserving the value.
            return json_encode($value);
        }

        return $this->escapeClickHouseString((string) $value);
    }

    /**
     * Compile a PHP array into a ClickHouse literal: lists become Array literals
     * ("['a', 'b']"), associative arrays become Map construction calls
     * ("map('k', 'v', ...)"). Elements recurse through parameter(), so nesting and
     * escaping behave exactly like scalar values.
     *
     * @param  array<int|string, mixed>  $value
     */
    private function compileArrayValue(array $value): string
    {
        if (array_is_list($value)) {
            $items = array_map(fn ($item): string => (string) $this->parameter($item), $value);

            return '['.implode(', ', $items).']';
        }

        $pairs = [];

        foreach ($value as $key => $item) {
            $pairs[] = (string) $this->parameter(is_int($key) ? $key : (string) $key);
            $pairs[] = (string) $this->parameter($item);
        }

        return 'map('.implode(', ', $pairs).')';
    }

    /**
     * ClickHouse has no standard UPDATE; compile to an ALTER TABLE mutation. Overridden
     * at this level (not compileUpdateWithoutJoins) because PostgresGrammar diverts
     * queries with a limit into a ctid-subquery form that ClickHouse cannot run. A
     * "limit" has no mutation equivalent and is ignored — Laravel's updateOrInsert()
     * adds a defensive limit(1) to updates targeting a unique row.
     *
     * @param  array<string, mixed>  $values
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

    /**
     * @param  array<int, array<string, mixed>>  $wheres
     * @return array<int, array<string, mixed>>
     */
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
     *
     * @param  array<string, mixed>  $values
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
     *
     * @param  array<int, array<string, mixed>>  $values
     * @param  array<int, string>  $uniqueBy
     * @param  array<int|string, mixed>  $update
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
     *
     * @return array<string, list<mixed>>
     */
    public function compileTruncate(Builder $query): array
    {
        return ['truncate table '.$this->wrapTable($query->from) => []];
    }

    /**
     * insertOrIgnore inherits Postgres' "on conflict do nothing", which ClickHouse
     * rejects. Fail loudly instead of emitting SQL that only breaks against the server.
     *
     * @param  array<string, mixed>  $values
     */
    public function compileInsertOrIgnore(Builder $query, array $values): string
    {
        throw new RuntimeException(
            'ClickHouse does not support insertOrIgnore (ON CONFLICT); use insert() and '
            .'deduplicate with a ReplacingMergeTree engine.',
        );
    }

    /**
     * @param  array<int, string>  $columns
     */
    public function compileInsertOrIgnoreUsing(Builder $query, array $columns, string $sql): string
    {
        throw new RuntimeException(
            'ClickHouse does not support insertOrIgnore (ON CONFLICT); use insertUsing() and '
            .'deduplicate with a ReplacingMergeTree engine.',
        );
    }

    /**
     * The JSON where clauses inherit Postgres' jsonb operators (@>, ->, ->>, ?),
     * which ClickHouse cannot run — in ClickHouse '->' is lambda syntax. Fail loudly;
     * use a raw where with ClickHouse's JSONExtractString / JSONHas functions instead.
     *
     * @param  array<string, mixed>  $where
     */
    protected function whereJsonContains(Builder $query, $where): string
    {
        throw $this->unsupportedJson('whereJsonContains');
    }

    /**
     * @param  array<string, mixed>  $where
     */
    protected function whereJsonContainsKey(Builder $query, $where): string
    {
        throw $this->unsupportedJson('whereJsonContainsKey');
    }

    /**
     * @param  array<string, mixed>  $where
     */
    protected function whereJsonOverlaps(Builder $query, $where): string
    {
        throw $this->unsupportedJson('whereJsonOverlaps');
    }

    /**
     * @param  array<string, mixed>  $where
     */
    protected function whereJsonLength(Builder $query, $where): string
    {
        throw $this->unsupportedJson('whereJsonLength');
    }

    protected function wrapJsonSelector($value): string
    {
        throw $this->unsupportedJson('JSON column access (->)');
    }

    private function unsupportedJson(string $feature): RuntimeException
    {
        return new RuntimeException(
            "ClickHouse does not support {$feature} (Postgres jsonb syntax). Use a raw "
            .'where/select with ClickHouse JSON functions (JSONExtractString, JSONHas, ...).',
        );
    }

    /**
     * Inline bindings into raw SQL using ClickHouse-safe quoting. Mirrors the framework's
     * literal-aware scanner but escapes through parameter() instead of Connection::escape(),
     * which would call PDO::quote() on the non-PDO smi2 client.
     *
     * @param  array<int|string, mixed>  $bindings
     */
    public function substituteBindingsIntoRawSql($sql, $bindings): string
    {
        // Nothing to substitute: grammar-compiled statements arrive with values
        // already inlined by parameter(), so the placeholder scan below would walk
        // multi-megabyte bulk-insert SQL to replace nothing. Skip it.
        if ($bindings === [] || ! str_contains($sql, '?')) {
            return $sql;
        }

        $bindings = array_values($bindings);
        $query = '';
        $bindingIndex = 0;
        // The current quoting context, or null when outside any quote. A '?' is a
        // binding placeholder ONLY outside quotes — never inside a single-quoted
        // string literal, nor inside a double-quoted or backtick-quoted identifier.
        // ClickHouse processes backslash escapes and doubled quotes in all three,
        // so a '?' embedded in a quoted identifier must not be substituted (that
        // would let a bound value break out of the identifier into executable SQL).
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $nextChar = $sql[$i + 1] ?? null;

            if ($quote !== null) {
                if ($char === '\\' && $nextChar !== null) {
                    $query .= $char.$nextChar;
                    $i++;
                } elseif ($char === $quote && $nextChar === $quote) {
                    $query .= $char.$nextChar;
                    $i++;
                } else {
                    $query .= $char;
                    if ($char === $quote) {
                        $quote = null;
                    }
                }
            } elseif ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $query .= $char;
            } elseif ($char === '?' && $nextChar === '?') {
                // Literal '??' stays as-is, matching the framework scanner.
                $query .= '??';
                $i++;
            } elseif ($char === '?') {
                // array_key_exists (not ??) so a genuine null binding inlines as
                // NULL rather than being treated as a missing binding.
                $query .= array_key_exists($bindingIndex, $bindings)
                    ? (string) $this->parameter($bindings[$bindingIndex++])
                    : '?';
            } else {
                $query .= $char;
            }
        }

        return $query;
    }
}
