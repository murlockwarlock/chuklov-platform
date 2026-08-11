# Deployment

M16 will implement ADR-014: exact revision → locked install/build → shared environment/private storage → preflight → backward-compatible migration → health check → atomic revision switch → graceful Horizon reload → recorded revision.

`make deploy` is intentionally guarded until this runbook is implemented and production authority/configuration exists.
