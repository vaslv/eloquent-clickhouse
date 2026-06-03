<?php

namespace Timeleads\EloquentClickHouse;

use ClickHouseDB\Client;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Processors\Processor;

class ClickHouseConnection extends Connection
{
    protected Client $client;

    public function __construct(Client $client, $database, $tablePrefix = '', array $config = [])
    {
        $this->client = $client;

        /** @noinspection PhpParamsInspection */
        parent::__construct($client, $database, $tablePrefix, $config);
    }

    public function getDefaultQueryGrammar(): QueryGrammar
    {
        return new QueryGrammar($this);
    }

    public function getDefaultPostProcessor(): Processor
    {
        return new Processor;
    }

    public function getDefaultSchemaGrammar(): SchemaGrammar
    {
        return new SchemaGrammar($this);
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return [];
            }

            return $this->client->select($this->inlineBindings($query, $bindings))->rows();
        });
    }

    public function insert($query, $bindings = []): bool
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            $this->client->write($this->inlineBindings($query, $bindings));

            return true;
        });
    }

    protected function inlineBindings(string $query, array $bindings): string
    {
        return $this->getQueryGrammar()->substituteBindingsIntoRawSql($query, $bindings);
    }

    public function beginTransaction() {}

    public function commit() {}

    public function rollBack($toLevel = null) {}
}
