# Data Classification

| Class | Examples | Required handling |
|---|---|---|
| A Root secrets | application encryption key, infrastructure credentials | Runtime secret store; never database/Git/logs |
| B Integration secrets | channel/payment/calendar/API credentials | Framework encryption, masked replacement/rotation, audit |
| C Sensitive personal/medical | identity, complaints, records, photos, reports | Minimize, authorize, private storage, retention/access audit as required |
| D Operational | service catalog, safe settings, job identifiers | Organization scope and normal authorization |

Field-level encryption/search decisions require a focused ADR; do not blindly encrypt whole records or invent custom cryptography.
