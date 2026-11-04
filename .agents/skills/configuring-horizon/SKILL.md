---
name: configuring-horizon
description: Use when a Chuklov task explicitly changes or troubleshoots Laravel Horizon configuration, workers, dashboard authorization, metrics, tags, notifications, or timeout ordering.
---

# Horizon Configuration

Use only for Horizon installation, `config/horizon.php`, supervisor/queue
configuration, dashboard authorization, metrics/snapshot scheduling, job tags,
notifications, or Horizon worker/runtime failures. Do not trigger it for generic
Laravel queues, Redis setup, or ordinary jobs.

Use the installed Laravel/Horizon version and Boost documentation search. Read
the relevant reference under `references/` only for the requested topic:
supervisors, notifications, tags, or metrics.

Keep dashboard access explicitly authorized. Check the current
`config/horizon.php` before changing supervisor blocks; environment settings
merge into defaults. Preserve the timeout ordering
`job timeout < supervisor timeout < retry_after` and configure snapshot
scheduling when metrics are required.

Verify the changed behavior in hosted CI or authorized staging. Running
`php artisan horizon`, starting Redis, or opening the dashboard locally is
not a default prerequisite; do it only when the task explicitly requires a
safe local reproduction and the required runtime is already available.
