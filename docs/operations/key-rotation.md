# Key Rotation

Root application/infrastructure keys stay outside the database and are rotated with a staged decrypt/re-encrypt plan and verified backup. Integration credentials support masked replacement, audit, and adapter-specific validation. Do not invent custom cryptography.
