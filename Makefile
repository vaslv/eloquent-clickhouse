COMPOSE := docker compose
PHP := $(COMPOSE) exec -T php

.DEFAULT_GOAL := help

help: ## Show available targets
	@grep -hE '^[a-zA-Z0-9_.-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-18s\033[0m %s\n",$$1,$$2}'

build: ## Build the PHP image
	$(COMPOSE) build

up: ## Start ClickHouse + PHP (builds if needed; waits for ClickHouse health)
	$(COMPOSE) up -d --build

down: ## Stop and remove containers (keeps data volume)
	$(COMPOSE) down

clean: ## Stop and remove containers AND volumes (drops ClickHouse data)
	$(COMPOSE) down -v

install: ## composer install inside the container (respects composer.lock)
	$(PHP) composer install

update: ## composer update inside the container
	$(PHP) composer update

test: ## Run unit tests
	$(PHP) composer test

test-integration: ## Run integration tests (requires ClickHouse up)
	$(PHP) composer test:integration

test-all: ## Run unit + integration tests
	$(PHP) composer test:all

pint: ## Run Laravel Pint formatter
	$(PHP) composer format

sh: ## Open a shell in the PHP container
	$(COMPOSE) exec php bash

ch: ## Open the ClickHouse client
	$(COMPOSE) exec clickhouse clickhouse-client --user $${CLICKHOUSE_USERNAME:-eloquent} --password $${CLICKHOUSE_PASSWORD:-secret}

logs: ## Tail ClickHouse logs
	$(COMPOSE) logs -f clickhouse

.PHONY: help build up down clean install update test test-integration test-all pint sh ch logs
