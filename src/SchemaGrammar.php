<?php

namespace Vaslv\EloquentClickHouse;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;
use RuntimeException;
use UnitEnum;
use Vaslv\EloquentClickHouse\Concerns\EscapesClickHouseStrings;

use function Illuminate\Support\enum_value;

class SchemaGrammar extends Grammar
{
    use EscapesClickHouseStrings;

    /**
     * The possible column modifiers.
     *
     * @var string[]
     */
    protected $modifiers = ['Default', 'Comment', 'After', 'First'];

    public function compileTableExists($schema, $table): string
    {
        return sprintf(
            'select exists (select 1 from system.tables where database = %s and name = %s)',
            $schema ? $this->escapeClickHouseString($schema) : 'currentDatabase()',
            $this->escapeClickHouseString($table),
        );
    }

    public function compileTables($schema): string
    {
        return sprintf(
            'select name, database as schema, total_bytes as size, comment, engine '
                .'from system.tables where database %s order by name',
            $this->compileSchemaPredicate($schema),
        );
    }

    public function compileColumns($schema, $table): string
    {
        return sprintf(
            'select name, type, default_kind, default_expression, comment '
                .'from system.columns where database %s and table = %s order by position',
            $this->compileSchemaPredicate($schema),
            $this->escapeClickHouseString($table),
        );
    }

    public function compileViews($schema): string
    {
        // Matches every view flavour: View, MaterializedView, LiveView, WindowView.
        return sprintf(
            'select name, database as schema, create_table_query as definition '
                ."from system.tables where engine like '%%View' and database %s order by name",
            $this->compileSchemaPredicate($schema),
        );
    }

    /**
     * @param  string|string[]|null  $schema
     */
    private function compileSchemaPredicate($schema): string
    {
        if (empty($schema)) {
            return '= currentDatabase()';
        }

        $schemas = array_map($this->escapeClickHouseString(...), (array) $schema);

        return count($schemas) === 1 ? '= '.$schemas[0] : 'in ('.implode(', ', $schemas).')';
    }

    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        $sql = sprintf(
            'create %stable %s (%s)',
            $blueprint->temporary ? 'temporary ' : '',
            $this->wrapTable($blueprint),
            implode(', ', $this->getColumns($blueprint)),
        );

        // Temporary tables always use the Memory engine and reject an ENGINE clause.
        if ($blueprint->temporary) {
            return $sql;
        }

        return $sql.$this->compileEngineClause($blueprint);
    }

    private function compileEngineClause(Blueprint $blueprint): string
    {
        $engine = $blueprint->engine
            ?? $this->connection->getConfig('engine')
            ?? 'MergeTree';

        $sql = " engine = {$engine}";

        // MergeTree-family engines require a sorting key. The blueprint's primary key
        // becomes the sorting key (ClickHouse derives the primary key from it); without
        // one the table is unsorted. An engine string carrying its own "order by" is
        // passed through untouched.
        if (stripos($engine, 'mergetree') !== false && stripos($engine, 'order by') === false) {
            $sql .= ' order by '.($this->compileSortingKey($blueprint) ?? 'tuple()');
        }

        return $sql;
    }

    private function compileSortingKey(Blueprint $blueprint): ?string
    {
        foreach ($blueprint->getCommands() as $command) {
            if ($command->name !== 'primary') {
                continue;
            }

            // ClickHouse rejects nullable sorting-key columns unless the
            // allow_nullable_key table setting is enabled. Fail at compile time
            // with a clear message instead of an opaque server-side Code 44.
            foreach ($blueprint->getAddedColumns() as $column) {
                if ($column->nullable && in_array($column->name, $command->columns, true)) {
                    throw new RuntimeException(
                        "ClickHouse sorting keys cannot contain nullable columns (\"{$column->name}\"); "
                        .'drop nullable() or create the table with a raw statement using the allow_nullable_key setting.',
                    );
                }
            }

            return '('.$this->columnize($command->columns).')';
        }

        return null;
    }

    public function compilePrimary(Blueprint $blueprint, Fluent $command): ?string
    {
        // Consumed by compileCreate() as the MergeTree sorting key.
        if ($blueprint->creating()) {
            return null;
        }

        throw new RuntimeException('ClickHouse does not support adding a primary key to an existing table.');
    }

    public function compileAdd(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'alter table %s add column %s',
            $this->wrapTable($blueprint),
            $this->getColumn($blueprint, $command->column),
        );
    }

    public function compileChange(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'alter table %s modify column %s',
            $this->wrapTable($blueprint),
            $this->getColumn($blueprint, $command->column),
        );
    }

    public function compileDropColumn(Blueprint $blueprint, Fluent $command): string
    {
        $columns = array_map(fn ($column) => 'drop column '.$this->wrap($column), $command->columns);

        return 'alter table '.$this->wrapTable($blueprint).' '.implode(', ', $columns);
    }

    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table '.$this->wrapTable($blueprint);
    }

    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table if exists '.$this->wrapTable($blueprint);
    }

    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        return 'rename table '.$this->wrapTable($blueprint).' to '.$this->wrapTable($command->to);
    }

    // Blueprint silently skips commands whose compile method does not exist, so the
    // unsupported ones must fail loudly instead of pretending to have run.

    public function compileIndex(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse does not support conventional indexes; use DB::statement() for data-skipping indexes.');
    }

    public function compileUnique(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse does not support unique indexes.');
    }

    public function compileSpatialIndex(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse does not support spatial indexes.');
    }

    public function compileForeign(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse does not support foreign keys.');
    }

    public function compileDropIndex(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse does not support conventional indexes.');
    }

    public function compileDropUnique(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse does not support unique indexes.');
    }

    public function compileDropForeign(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse does not support foreign keys.');
    }

    public function compileDropPrimary(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException('ClickHouse does not support dropping a primary key.');
    }

    protected function getType(Fluent $column): string
    {
        if ($column->autoIncrement) {
            throw new RuntimeException(
                'ClickHouse does not support auto-incrementing columns (id()/increments()); use uuid() or an externally generated identifier.',
            );
        }

        $type = parent::getType($column);

        return $column->nullable ? 'Nullable('.$type.')' : $type;
    }

    protected function typeChar(Fluent $column): string
    {
        return 'String';
    }

    protected function typeString(Fluent $column): string
    {
        return 'String';
    }

    protected function typeTinyText(Fluent $column): string
    {
        return 'String';
    }

    protected function typeText(Fluent $column): string
    {
        return 'String';
    }

    protected function typeMediumText(Fluent $column): string
    {
        return 'String';
    }

    protected function typeLongText(Fluent $column): string
    {
        return 'String';
    }

    protected function typeInteger(Fluent $column): string
    {
        return $column->unsigned ? 'UInt32' : 'Int32';
    }

    protected function typeBigInteger(Fluent $column): string
    {
        return $column->unsigned ? 'UInt64' : 'Int64';
    }

    protected function typeMediumInteger(Fluent $column): string
    {
        return $column->unsigned ? 'UInt32' : 'Int32';
    }

    protected function typeSmallInteger(Fluent $column): string
    {
        return $column->unsigned ? 'UInt16' : 'Int16';
    }

    protected function typeTinyInteger(Fluent $column): string
    {
        return $column->unsigned ? 'UInt8' : 'Int8';
    }

    protected function typeFloat(Fluent $column): string
    {
        return $column->precision && $column->precision <= 24 ? 'Float32' : 'Float64';
    }

    protected function typeDouble(Fluent $column): string
    {
        return 'Float64';
    }

    protected function typeDecimal(Fluent $column): string
    {
        return sprintf('Decimal(%d, %d)', $column->total, $column->places);
    }

    protected function typeBoolean(Fluent $column): string
    {
        return 'Bool';
    }

    protected function typeEnum(Fluent $column): string
    {
        // Number the values explicitly: deterministic across ClickHouse versions and
        // makes the position->value mapping visible in the DDL. Enum8 holds Int8, so
        // 1-based numbering caps it at 127 values; fall back to Enum16 beyond that.
        $values = [];

        foreach (array_values($column->allowed) as $index => $value) {
            $values[] = $this->escapeClickHouseString((string) $value).' = '.($index + 1);
        }

        return sprintf(
            '%s(%s)',
            count($values) > 127 ? 'Enum16' : 'Enum8',
            implode(', ', $values),
        );
    }

    protected function typeJson(Fluent $column): string
    {
        return 'String';
    }

    protected function typeJsonb(Fluent $column): string
    {
        return 'String';
    }

    protected function typeDate(Fluent $column): string
    {
        return 'Date';
    }

    protected function typeDateTime(Fluent $column): string
    {
        if (! $column->precision) {
            return 'DateTime';
        }

        if ($column->precision < 0 || $column->precision > 9) {
            throw new RuntimeException(
                "ClickHouse DateTime64 precision must be between 0 and 9, got {$column->precision}.",
            );
        }

        return "DateTime64({$column->precision})";
    }

    protected function typeDateTimeTz(Fluent $column): string
    {
        return $this->typeDateTime($column);
    }

    protected function typeTimestamp(Fluent $column): string
    {
        return $this->typeDateTime($column);
    }

    protected function typeTimestampTz(Fluent $column): string
    {
        return $this->typeDateTime($column);
    }

    protected function typeTime(Fluent $column): never
    {
        throw new RuntimeException('ClickHouse does not have a TIME type; store a string or seconds instead.');
    }

    protected function typeTimeTz(Fluent $column): never
    {
        throw new RuntimeException('ClickHouse does not have a TIME type; store a string or seconds instead.');
    }

    protected function typeYear(Fluent $column): string
    {
        return 'UInt16';
    }

    protected function typeBinary(Fluent $column): string
    {
        return 'String';
    }

    protected function typeUuid(Fluent $column): string
    {
        return 'UUID';
    }

    protected function typeUlid(Fluent $column): string
    {
        return 'String';
    }

    protected function typeIpAddress(Fluent $column): string
    {
        return 'String';
    }

    protected function typeMacAddress(Fluent $column): string
    {
        return 'String';
    }

    protected function modifyDefault(Blueprint $blueprint, Fluent $column): ?string
    {
        if ($column->useCurrent && in_array($column->type, ['dateTime', 'dateTimeTz', 'timestamp', 'timestampTz'], true)) {
            return ' default now()';
        }

        if (! is_null($column->default)) {
            return ' default '.$this->getDefaultValue($column->default);
        }

        return null;
    }

    protected function modifyComment(Blueprint $blueprint, Fluent $column): ?string
    {
        return is_null($column->comment) ? null : ' comment '.$this->escapeClickHouseString($column->comment);
    }

    protected function modifyAfter(Blueprint $blueprint, Fluent $column): ?string
    {
        return is_null($column->after) ? null : ' after '.$this->wrap($column->after);
    }

    protected function modifyFirst(Blueprint $blueprint, Fluent $column): ?string
    {
        return is_null($column->first) ? null : ' first';
    }

    /**
     * The parent quotes every non-bool scalar as a string literal; ClickHouse does not
     * coerce string literals to numeric column types in DEFAULT expressions, and its
     * literals need backslash-aware escaping.
     */
    protected function getDefaultValue($value): float|int|string
    {
        if ($value instanceof Expression) {
            return $this->getValue($value);
        }

        if ($value instanceof UnitEnum) {
            $value = enum_value($value);
        }

        if (is_bool($value)) {
            return (int) $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return $this->escapeClickHouseString((string) $value);
    }
}
