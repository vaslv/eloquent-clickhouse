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
        return $this->getValue($value);
    }
}
