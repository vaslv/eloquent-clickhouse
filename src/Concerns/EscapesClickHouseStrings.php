<?php

namespace Vaslv\EloquentClickHouse\Concerns;

trait EscapesClickHouseStrings
{
    /**
     * Wrap a single identifier in double quotes for ClickHouse. The inherited
     * Grammar::wrapValue() only doubles embedded double-quotes; ClickHouse also
     * processes backslash escapes inside double-quoted identifiers (same lexer
     * path as string literals), so a name ending in a backslash could escape the
     * closing quote and break out of the identifier. Escape backslashes first,
     * then double-quotes — matching escapeClickHouseString()'s ordering.
     */
    protected function wrapValue($value)
    {
        if ($value === '*') {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '""'], $value).'"';
    }

    /**
     * Quote a string as a ClickHouse literal. ClickHouse processes backslash escapes
     * inside string literals, so backslashes must be escaped before quotes; otherwise
     * a value such as \' could break out of the literal.
     */
    protected function escapeClickHouseString(string $value): string
    {
        return "'".str_replace(
            ['\\', "'", "\0", "\r", "\n", "\t"],
            ['\\\\', "''", '\\0', '\\r', '\\n', '\\t'],
            $value,
        )."'";
    }
}
