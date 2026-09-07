# Appendix 2 — normalized multi-agent specification

Source: `ПРИЛОЖЕНИЕ №2: Спецификация Мультиагентного ИИ-Комплекса и Системных Промптов`.

The source roles map to the existing `AiCapability` registry and workflow engine. Provider/model examples in the source are illustrative only; model selection remains owned by the existing model-release policy.

## Agent 1 — Diagnostic Text OCR & Medical Document Parser

Capability: `clinical_document_extraction`.

Source inputs are medical document images, scans, or extracted report text. The intended result is valid structured JSON with:

- `exam_type`
- `anatomical_region`
- `key_findings[]` containing location, pathology, size in millimeters where explicitly present, and impact
- `structural_deformations[]`
- `critical_flags[]`
- `plain_summary`

The source prompt intent is to identify anatomical regions, concrete findings, dimensions, stenosis/root involvement, lordosis/kyphosis, facet/ligament findings, and significant versus ordinary/age-related findings when those facts are present in the source material.

Platform safety guardrails:

- A missing fact remains unknown/null/empty; the agent must not invent a diagnosis, measurement, contraindication, or severity.
- Results remain subject to the existing human-review and protected-medical-data boundaries.
- JSON-only output is required by the structured contract; Markdown wrappers are invalid.

## Agent 2 — Posture Vision & Biomechanics

Capability: `posture_analysis`.

The existing attachment boundary requires exactly three posture images: front, side, and back.

Source observation areas are head/ear level, shoulder level, waist asymmetry, pelvis, knee valgus/varus, and foot loading from front/back views; forward head, thoracic kyphosis, lumbar lordosis, pelvic tilt, and knee hyperextension from the side view.

The source result sections are:

1. Visual findings.
2. Leading compensatory patterns.
3. Suggested practitioner focus.

Platform safety guardrails:

- Do not invent precise angles or measurements that were not produced by a validated measurement system.
- Do not make a categorical diagnosis from images.
- The source mentions JSON vector shifts but does not define a complete schema. The permanent contract represents only the named report sections; the vector schema is a source gap.

## Agent 3 — Clinical Synthesizer & Case Summarizer

Capability: `clinical_synthesizer`.

The source input bundle is the client profile, complaints/goals, Agent 1 result, Agent 2 result, survey results, and past session history. Existing reference validation and context assembly remain the boundary for these inputs.

The practitioner-facing result sections are:

- Client summary.
- Main request.
- Root-cause hypothesis.
- Critical limitations/risks.
- Blind spots/questions to clarify.
- Recommended focus for the first session.

Platform safety guardrails:

- Source facts, AI hypotheses, and missing information are represented separately.
- A hypothesis is not a confirmed diagnosis.
- Missing Agent 1/2 results or unavailable survey results remain explicitly missing; they are not fabricated.
- Existing human review remains authoritative before practitioner use.

## Agent 4 — AI Companion & Scenario Engine

Capability: `client_companion`.

The source role is a warm, concise, helpful conversational assistant that supports booking, recognizes a user who explicitly identifies as a B2B specialist, and routes that user through the existing B2B/Zoom flow when appropriate. The source tone is a wise, knowledgeable, light, caring friend with restrained humor/light cynicism.

Platform safety guardrails:

- The existing Client Companion runtime, action validation, handoff state, and human authority remain canonical.
- No categorical diagnoses, unverified medical facts, invented treatment results, or fabricated progress percentages.
- A human-handoff state cannot be automatically resumed.
- Booking and B2B actions use existing application flows.
- Tracker/subscription behavior is deferred unless separately accepted and activated.

## Lifecycle status

These specifications define source-backed contracts and draft prompts. They do not by themselves prove that a medical upload has been processed through Agents 1–3 into a persisted practitioner-facing result. That end-to-end product workflow must be implemented and verified separately.
