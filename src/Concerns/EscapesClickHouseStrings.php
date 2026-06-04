<?php

namespace Timeleads\EloquentClickHouse\Concerns;

trait EscapesClickHouseStrings
{
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
