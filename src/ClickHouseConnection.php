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
        $statement = $this->client->select($query);

        return $statement->rows();
    }

    public function insert($query, $bindings = []): true
    {
        $this->client->write($query);

        return true;
    }

    public function beginTransaction() {}

    public function commit() {}

    public function rollBack($toLevel = null) {}
}
