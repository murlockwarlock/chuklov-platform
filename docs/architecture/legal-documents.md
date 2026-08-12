# Legal documents and consent evidence

Legal wording is configuration/content owned by the organization/platform/legal authority; it is never hardcoded in PHP or Vue.

M2 stores organization-scoped documents with document type, purpose, locale, management mode, lifecycle status, version, content, required/optional state, and effective/publication/archive instants. Drafts can change. Published content and version are immutable; a later version archives the prior lifecycle record without rewriting its content.

Portal consent evidence stores the organization, Client, exact legal-document version, subject, required state, accepted/declined state, timestamp, and safe source. It does not store tokens, cookies, raw Telegram initData, raw request bodies, or unrelated sensitive payloads. Composite organization/document/client constraints and Application queries prevent cross-organization evidence.

Phase 1 exposes only platform-controlled `PLATFORM_MANAGED` documents. The data model represents `ORGANIZATION_MANAGED` for future readiness, but no organization-facing action can enable that mode; a later platform entitlement/administration decision is required. This is not a generic CMS.
