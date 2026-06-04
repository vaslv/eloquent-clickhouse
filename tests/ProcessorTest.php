<?php

declare(strict_types=1);

namespace Timeleads\EloquentClickHouse\Tests;

use PHPUnit\Framework\TestCase;
use Timeleads\EloquentClickHouse\Processor;

final class ProcessorTest extends TestCase
{
    public function test_columns_are_normalised_to_the_schema_builder_shape(): void
    {
        $columns = (new Processor)->processColumns([
            [
                'name' => 'id',
                'type' => 'UInt64',
                'default_kind' => '',
                'default_expression' => '',
                'comment' => '',
            ],
            [
                'name' => 'label',
                'type' => 'Nullable(String)',
                'default_kind' => 'DEFAULT',
                'default_expression' => "'none'",
                'comment' => 'human label',
            ],
            [
                'name' => 'amount',
                'type' => 'Decimal(10, 2)',
                'default_kind' => 'MATERIALIZED',
                'default_expression' => 'price * qty',
                'comment' => '',
            ],
        ]);

        self::assertSame(
            [
                'name' => 'id',
                'type_name' => 'UInt64',
                'type' => 'UInt64',
                'collation' => null,
                'nullable' => false,
                'default' => null,
                'auto_increment' => false,
                'comment' => null,
                'generation' => null,
            ],
            $columns[0],
        );

        self::assertSame('String', $columns[1]['type_name']);
        self::assertSame('Nullable(String)', $columns[1]['type']);
        self::assertTrue($columns[1]['nullable']);
        self::assertSame("'none'", $columns[1]['default']);
        self::assertSame('human label', $columns[1]['comment']);

        self::assertSame('Decimal', $columns[2]['type_name']);
        self::assertNull($columns[2]['default']);
        self::assertSame(
            ['type' => 'materialized', 'expression' => 'price * qty'],
            $columns[2]['generation'],
        );
    }
}
