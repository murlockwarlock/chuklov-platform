COMPOSE := docker-compose
PHP := php

.PHONY: setup up down infra infra-wait test test-unit test-feature test-integration test-integration-foundation test-integration-rag test-integration-concurrency test-e2e lint static quality check-fast ci check-app-key check-docker-context scan-secrets privacy migrate seed backup deploy deploy-staging rollback

setup:
	test -f .env || cp .env.example .env
	$(COMPOSE) up -d postgres redis
	$(COMPOSE) run --rm app composer install --no-interaction --prefer-dist
	scripts/initialize-app-key.sh $(COMPOSE) run --rm app php artisan key:generate --no-interaction
	$(COMPOSE) run --rm node npm ci
	$(COMPOSE) run --rm app php artisan migrate --seed --force
	$(COMPOSE) run --rm app php artisan storage:link
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
	$(MAKE) test-integration-foundation test-integration-rag test-integration-concurrency

test-integration-foundation:
	DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=chuklov_test DB_USERNAME=chuklov DB_PASSWORD=chuklov_local CACHE_STORE=redis QUEUE_CONNECTION=redis REDIS_CLIENT=predis REDIS_HOST=127.0.0.1 $(PHP) artisan test tests/Integration/InfrastructureFoundationTest.php tests/Integration/MilestoneOneDatabaseTest.php tests/Integration/MilestoneTwoDatabaseTest.php tests/Integration/MilestoneThreeDatabaseTest.php tests/Integration/MilestoneFourDatabaseTest.php tests/Integration/MilestoneSixFinanceRolloutTest.php tests/Integration/MilestoneSevenDatabaseTest.php tests/Integration/MilestoneEightDatabaseTest.php tests/Integration/MilestoneElevenADatabaseTest.php tests/Integration/MilestoneElevenCAnalyticsPostgresTest.php

test-integration-rag:
	DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=chuklov_test DB_USERNAME=chuklov DB_PASSWORD=chuklov_local CACHE_STORE=array QUEUE_CONNECTION=sync $(PHP) artisan test tests/Integration/MilestoneNineDatabaseTest.php tests/Integration/PgvectorStatementTimeoutTest.php tests/Integration/KnowledgeRevisionsFilamentPostgresTest.php

test-integration-concurrency:
	DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=chuklov_test DB_USERNAME=chuklov DB_PASSWORD=chuklov_local CACHE_STORE=array QUEUE_CONNECTION=sync $(PHP) artisan test tests/Integration/ClientCompanionPostgresTest.php tests/Integration/MilestoneFourConcurrencyTest.php tests/Integration/MilestoneFiveConcurrencyTest.php tests/Integration/MilestoneSixFinanceConcurrencyTest.php tests/Integration/MilestoneNineConcurrencyTest.php tests/Integration/MilestoneTenConcurrencyTest.php tests/Integration/MilestoneElevenAConcurrencyTest.php tests/Integration/MilestoneElevenBBroadcastConcurrencyTest.php tests/Integration/B2bSalesCallPostgresTest.php tests/Integration/B2bZoomCredentialPostgresTest.php tests/Integration/KnowledgeStorageCleanupPostgresTest.php

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

check-fast: test lint static

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

deploy-staging:
	@test -n "$(REVISION)" || (echo "Usage: make deploy-staging REVISION=<full-sha> [EXPECTED_CURRENT_REVISION=<full-sha>]" >&2; exit 1)
	scripts/deploy-staging.sh "$(REVISION)" "$(EXPECTED_CURRENT_REVISION)"

rollback:
	@echo "Rollback is intentionally unavailable before Milestone 16." && exit 2
