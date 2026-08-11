COMPOSE := docker-compose
PHP := php

.PHONY: setup up down infra infra-wait test test-unit test-feature test-integration test-e2e lint static quality ci check-app-key check-docker-context scan-secrets privacy migrate seed backup deploy rollback

setup:
	test -f .env || cp .env.example .env
	$(COMPOSE) up -d postgres redis
	$(COMPOSE) run --rm app composer install --no-interaction --prefer-dist
	scripts/initialize-app-key.sh $(COMPOSE) run --rm app php artisan key:generate --no-interaction
	$(COMPOSE) run --rm node npm ci
	$(COMPOSE) run --rm app php artisan migrate --seed --force
	$(COMPOSE) run --rm node npm run build

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

infra:
	$(COMPOSE) up -d postgres redis

infra-wait:
	$(COMPOSE) up -d --wait postgres redis
	$(COMPOSE) exec -T postgres pg_isready -U chuklov -d chuklov
	$(COMPOSE) exec -T redis redis-cli ping

test: test-unit test-feature

test-unit:
	$(PHP) artisan test tests/Unit

test-feature:
	$(PHP) artisan test tests/Feature

test-integration:
	DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=chuklov_test DB_USERNAME=chuklov DB_PASSWORD=chuklov_local CACHE_STORE=redis QUEUE_CONNECTION=redis REDIS_CLIENT=predis REDIS_HOST=127.0.0.1 $(PHP) artisan test tests/Integration

test-e2e:
	npm run test:e2e

lint:
	composer lint
	npm run lint

static:
	composer static
	npm run typecheck

quality: test lint static
	npm run build
	composer audit --locked --no-interaction
	npm audit --audit-level=high

ci: infra-wait quality test-integration

check-app-key:
	scripts/check-app-key-idempotence.sh

check-docker-context:
	scripts/check-docker-context.sh

scan-secrets:
	scripts/scan-secrets.sh

privacy: check-docker-context scan-secrets

migrate:
	$(PHP) artisan migrate

seed:
	$(PHP) artisan db:seed

backup:
	./scripts/backup.sh

deploy:
	@echo "Deployment is intentionally unavailable before Milestone 16." && exit 2

rollback:
	@echo "Rollback is intentionally unavailable before Milestone 16." && exit 2
