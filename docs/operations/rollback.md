# Rollback

Rollback switches the `current` symlink to the last healthy compatible release, reloads application/worker processes, and verifies health. It never automatically reverses destructive database changes. `make rollback` remains guarded until M16.
