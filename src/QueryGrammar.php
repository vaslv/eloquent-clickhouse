<?php

namespace Timeleads\EloquentClickHouse;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

class QueryGrammar extends PostgresGrammar
{
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
            return $this->escapeStringLiteral($value);
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return $this->escapeStringLiteral((string) $value);
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

    private function escapeStringLiteral(string $value): string
    {
        // ClickHouse processes backslash escapes inside string literals, so backslashes must be
        // escaped before quotes; otherwise a value such as \' could break out of the literal.
        return "'".str_replace(
            ['\\', "'", "\0", "\r", "\n", "\t"],
            ['\\\\', "''", '\\0', '\\r', '\\n', '\\t'],
            $value,
        )."'";
    }
}
