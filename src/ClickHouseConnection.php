<?php

namespace Vaslv\EloquentClickHouse;

use ClickHouseDB\Client;
use Generator;
use Illuminate\Database\Connection;

class ClickHouseConnection extends Connection
{
    /**
     * @param  array<string, mixed>  $config
     */
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

    public function getSchemaBuilder(): SchemaBuilder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     * @param  array<string, mixed>  $fetchUsing
     * @return list<array<string, mixed>>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            return $this->client($useReadPdo)->select($this->inlineBindings($query, $bindings))->rows();
        });
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     */
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

    /**
     * @param  array<int|string, mixed>  $bindings
     */
    protected function inlineBindings(string $query, array $bindings): string
    {
        return $this->getQueryGrammar()->substituteBindingsIntoRawSql($query, $bindings);
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     */
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

    /**
     * @param  array<int|string, mixed>  $bindings
     */
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

    /**
     * @param  array<int|string, mixed>  $bindings
     * @param  array<string, mixed>  $fetchUsing
     * @return Generator<int, array<string, mixed>>
     */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): Generator
    {
        // Stream rows through smi2's selectGenerator (JSONEachRow over a php://temp
        // stream that spills to disk past 2MB), so a large cursor holds one decoded row
        // in memory instead of the whole result set — which is exactly why callers reach
        // for cursor(). selectGenerator() landed in smi2 1.24.406; on older releases
        // (the ^1.6 floor) fall back to an eager fetch so cursor() keeps working. The
        // work happens inside run() so the HTTP request (and any connection failure)
        // still gets logging, QueryException wrapping and the lost-connection retry.
        $result = $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            $client = $this->client($useReadPdo);
            $sql = $this->inlineBindings($query, $bindings);

            if (! method_exists($client, 'selectGenerator')) {
                return $client->select($sql)->rows();
            }

            $rows = $client->selectGenerator($sql);
            $rows->current(); // Force the request now, inside run()'s protection.

            return $rows;
        });

        if ($result instanceof Generator) {
            // Drive the primed generator through the Iterator protocol rather than
            // `yield from`: the latter rewinds, which throws on an already-started (or
            // exhausted, for an empty result) generator. current()/next() do not.
            while ($result->valid()) {
                yield $result->key() => $result->current();
                $result->next();
            }

            return;
        }

        yield from $result;
    }

    public function beginTransaction() {}

    public function commit() {}

    public function rollBack($toLevel = null) {}
}
