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

    protected function dateBasedWhere($type, Builder $query, $where): string
    {
        $value = $this->parameter($where['value']);

        return $type.'('.$this->wrap($where['column']).') '.$where['operator'].' \''.$value.'\'';
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
            return "'".str_replace("'", "''", $value)."'";
        }

        return $value;
    }
}
