<?php

namespace Timeleads\EloquentClickHouse;

use Illuminate\Database\Query\Processors\Processor as BaseProcessor;

class Processor extends BaseProcessor
{
    /**
     * Normalise system.columns rows (see SchemaGrammar::compileColumns) into the shape
     * Schema\Builder::getColumns() documents. The base processor would pass the raw
     * rows through, leaving the documented keys (type_name, nullable, ...) missing.
     */
    public function processColumns($results)
    {
        return array_map(function ($result) {
            $result = (array) $result;

            $type = $result['type'];
            $nullable = str_starts_with($type, 'Nullable(');
            $bareType = $nullable ? substr($type, 9, -1) : $type;

            $defaultKind = $result['default_kind'] ?? '';

            return [
                'name' => $result['name'],
                'type_name' => preg_replace('/\(.*/s', '', $bareType),
                'type' => $type,
                'collation' => null,
                'nullable' => $nullable,
                'default' => $defaultKind === 'DEFAULT' ? $result['default_expression'] : null,
                'auto_increment' => false,
                'comment' => ($result['comment'] ?? '') !== '' ? $result['comment'] : null,
                'generation' => in_array($defaultKind, ['MATERIALIZED', 'ALIAS'], true)
                    ? ['type' => strtolower($defaultKind), 'expression' => $result['default_expression']]
                    : null,
            ];
        }, $results);
    }
}
