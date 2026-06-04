<?php

namespace Timeleads\EloquentClickHouse;

use ClickHouseDB\Client;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Processors\Processor;

class ClickHouseConnection extends Connection
{
    public function __construct(Client $client, $database, $tablePrefix = '', array $config = [])
    {
        /** @noinspection PhpParamsInspection */
        parent::__construct($client, $database, $tablePrefix, $config);
    }

    /**
     * The live smi2 client. Always read through getPdo()/getReadPdo() — never cached —
     * so the framework's reconnect()/disconnect() lifecycle (which swaps $pdo) and the
     * lost-connection retry in run() actually take effect.
     */
    protected function client(bool $useReadPdo = false): Client
    {
        $client = $useReadPdo ? $this->getReadPdo() : $this->getPdo();

        assert($client instanceof Client);

        return $client;
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
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            return $this->client($useReadPdo)->select($this->inlineBindings($query, $bindings))->rows();
        });
    }

    public function insert($query, $bindings = []): bool
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            $this->client()->write($this->inlineBindings($query, $bindings));

            return true;
        });
    }

    protected function inlineBindings(string $query, array $bindings): string
    {
        return $this->getQueryGrammar()->substituteBindingsIntoRawSql($query, $bindings);
    }

    public function statement($query, $bindings = []): bool
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            $this->client()->write($this->inlineBindings($query, $bindings));

            return true;
        });
    }

    public function affectingStatement($query, $bindings = []): int
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return 0;
            }

            $this->client()->write($this->inlineBindings($query, $bindings));

            // ClickHouse's HTTP interface does not report an affected-row count.
            return 0;
        });
    }

    public function unprepared($query): bool
    {
        return $this->run($query, [], function ($query) {
            if ($this->pretending()) {
                return true;
            }

            $this->client()->write($query);

            return true;
        });
    }

    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): Generator
    {
        if ($this->pretending()) {
            return;
        }

        foreach ($this->client($useReadPdo)->select($this->inlineBindings($query, $bindings))->rows() as $row) {
            yield $row;
        }
    }

    public function beginTransaction() {}

    public function commit() {}

    public function rollBack($toLevel = null) {}
}
