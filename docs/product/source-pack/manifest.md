# Source manifest

## Priority

The repository uses this order when sources conflict:

1. Latest confirmed client behavior, v2.2, and the client changelog.
2. A recovered appendix, but only within the scope explicitly covered by that appendix.
3. Product requirements and accepted architecture decisions.
4. Installed code, migrations, contracts, and tests.
5. Official documentation for installed dependencies.

Appendix 1 and Appendix 2 are authoritative source documents for their own content. They do not supersede newer booking, location, timezone, security, privacy, legal, or runtime decisions.

## Recovered documents

| Source | Status | Provenance | Authoritative scope | Explicit boundary |
| --- | --- | --- | --- | --- |
| `11.08.26 Приложение 1. Анкета и Onboarding.docx` | FOUND | Recovered from the local Downloads folder; filename date `11.08.26`; original author/creation metadata is not treated as reliable | WebApp onboarding questionnaire, progressive profiling, attribution visibility, B2B self-declaration, clinical-profile fields, service/format selection, legal consent and attachment intent | Does not contain the complete 9 systems/MSQ questionnaire or scoring; newer v2.2 booking/location/timezone rules win |
| `11_08_26_Приложение_2_Промпты_для_агентов.docx` | FOUND | Recovered from the local Downloads folder; filename date `11_08_26`; original author/creation metadata is not treated as reliable | Intended roles, inputs, baseline prompt intent, and output sections for Agents 1–4 | Does not define a complete posture vector schema, provider/model authority, or permission to activate clinical workflows |

The binary DOCX files remain outside the repository. The normalized Markdown and JSON files in this pack are the reviewable source-backed representation.

## Remaining source status

| Source item | Status | Record |
| --- | --- | --- |
| Appendix 1 onboarding source | FOUND | Normalized in `appendix-1-normalized.md` |
| Appendix 2 specialized-agent prompt source | FOUND | Normalized in `appendix-2-normalized.md` |
| 9 systems/MSQ questionnaire bodies, scoring, thresholds, tags, result text, and locale/licensing rules | MISSING SOURCE | OQ-015 remains open and is the only reason that source question remains blocked |
| Appendix 2 posture “JSON vector shifts” schema | MISSING SOURCE | The appendix names the concept but does not define a complete authoritative schema; no permanent schema is invented |
