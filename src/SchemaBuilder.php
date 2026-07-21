<?php

namespace Vaslv\EloquentClickHouse;

use Illuminate\Database\Schema\Builder;

class SchemaBuilder extends Builder
{
    /**
     * The base builder throws a LogicException here, which would break
     * migrate:fresh. ClickHouse can drop any table with DROP TABLE, so
     * enumerate system.tables and drop the non-views.
     */
    public function dropAllTables(): void
    {
        foreach ($this->getTables() as $table) {
            if (str_contains((string) $table['engine'], 'View')) {
                continue;
            }

            $this->connection->statement(
                'drop table if exists '.$this->quotePhysicalName($table['name']),
            );
        }
    }

    public function dropAllViews(): void
    {
        foreach ($this->getViews() as $view) {
            // DROP TABLE is valid for every ClickHouse view flavour
            // (View, MaterializedView, LiveView, WindowView).
            $this->connection->statement(
                'drop table if exists '.$this->quotePhysicalName($view['name']),
            );
        }
    }

    /**
     * system.tables returns physical names, which already include any configured
     * table prefix; wrapTable() would re-apply it ("app_app_events"), silently
     * skipping every table on prefixed connections.
     */
    private function quotePhysicalName(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
