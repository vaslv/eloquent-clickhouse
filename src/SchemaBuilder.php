<?php

namespace Timeleads\EloquentClickHouse;

use Illuminate\Database\Schema\Builder;

class SchemaBuilder extends Builder
{
    /**
     * The base builder throws a LogicException here, which would break
     * migrate:fresh. ClickHouse can drop any table (views included) with
     * DROP TABLE, so enumerate system.tables and drop the non-views.
     */
    public function dropAllTables(): void
    {
        foreach ($this->getTables() as $table) {
            if (str_contains((string) $table['engine'], 'View')) {
                continue;
            }

            $this->connection->statement(
                'drop table if exists '.$this->grammar->wrapTable($table['name']),
            );
        }
    }

    public function dropAllViews(): void
    {
        foreach ($this->getTables() as $table) {
            if (str_contains((string) $table['engine'], 'View')) {
                // DROP TABLE is valid for every ClickHouse view flavour
                // (View, MaterializedView, LiveView, WindowView).
                $this->connection->statement(
                    'drop table if exists '.$this->grammar->wrapTable($table['name']),
                );
            }
        }
    }
}
