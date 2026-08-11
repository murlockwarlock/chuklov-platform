---
paths:
  - '{.dockerignore,docker/**,scripts/check-docker-context.sh}'
  - '{Makefile,composer.json,scripts/initialize-app-key.sh,scripts/check-app-key-idempotence.sh}'
---

# General

## Keep Docker build context deny-by-default
The PHP runtime image does not copy application source. Keep the Docker context deny-by-default and allow only reviewed Dockerfiles; `make check-docker-context` must prove private, IDE, report, and runtime paths are excluded.

## Never rotate APP_KEY during setup
Routine setup generates APP_KEY only when it is absent or blank. Repeated `make setup` and `composer setup` must preserve the existing key; intentional rotation is a separate authorized operation.
