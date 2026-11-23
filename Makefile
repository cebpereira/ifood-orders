.DEFAULT_GOAL := help

export UID := $(shell id -u)
export GID := $(shell id -g)

COMPOSE := docker compose
PHP     := $(COMPOSE) run --rm --no-deps app
ARTISAN := $(COMPOSE) exec app php artisan
NPM     := $(COMPOSE) run --rm --no-deps vite npm
PHP_DB  := $(COMPOSE) run --rm app

.PHONY: help setup build up dev down restart logs shell \
        install migrate fresh test lint fix stan ci assets poll reload

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

setup: ## First run: .env, images, dependencies, key, migrations and assets
	@test -f .env || cp .env.example .env
	$(MAKE) build install
	$(COMPOSE) up -d --wait postgres redis
	$(PHP) php artisan key:generate --ansi
	$(PHP) php artisan migrate --force
	$(MAKE) assets up
	@printf "\nPainel em http://localhost:$${APP_PORT:-8000}\n"

build: ## Build the PHP image
	$(COMPOSE) build

install: ## Install Composer and npm dependencies
	$(PHP) composer install
	$(NPM) ci

up: ## Start app, Horizon, scheduler, Postgres and Redis
	$(COMPOSE) up -d

dev: ## Start everything plus the Vite dev server (hot reload)
	$(COMPOSE) --profile dev up -d

down: ## Stop all containers
	$(COMPOSE) --profile dev down

restart: down up ## Restart all containers

reload: ## Reload Horizon workers and scheduler with new code
	$(COMPOSE) exec horizon php artisan horizon:terminate
	$(COMPOSE) restart scheduler

logs: ## Follow logs (e.g. make logs s=horizon)
	$(COMPOSE) logs -f $(s)

shell: ## Open a shell in the app container
	$(COMPOSE) exec app bash

migrate: ## Run migrations
	$(ARTISAN) migrate

fresh: ## Drop everything and migrate again
	$(ARTISAN) migrate:fresh

assets: ## Build front-end assets for production
	$(NPM) run build

test: ## Run the Pest suite (against the ifood_orders_test database)
	$(PHP_DB) ./vendor/bin/pest $(args)

lint: ## Check code style (Pint)
	$(PHP) ./vendor/bin/pint --test

fix: ## Fix code style (Pint)
	$(PHP) ./vendor/bin/pint

stan: ## Static analysis (PHPStan/Larastan)
	$(PHP) ./vendor/bin/phpstan analyse --memory-limit=1G

ci: lint stan test ## Run what CI runs

poll: ## Run one iFood polling cycle manually
	$(ARTISAN) ifood:poll
