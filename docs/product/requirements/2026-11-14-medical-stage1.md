# Medical Stage 1 Requirements, 2026-11-14

This is the canonical entrypoint for the medical requirements superseding the 2026-10-23 medical TZ. It is documentation only. It does not prove implementation, verification, staging readiness, or owner acceptance.

The complete test-engine ledger is in [`2026-11-14-medical-test-catalog.md`](2026-11-14-medical-test-catalog.md). Read this file first, then follow the catalog link for Stage 1B test details.

## Authority and source files

The requirements below are an engineering transcription of these three primary sources, read in full on 2026-11-14:

1. `Этапы реализации CRM Чуклова 14.11.26 — для Ивана.docx`
2. `ТЗ для Ивана этап 1 — диагностика, карта клиента и карта сеанса 14.11.26.docx`
3. `Каталог тестов как вносится результат 14.11.26.docx`

Authority order for this package:

1. The current owner/task instruction.
2. The detailed Stage 1 TZ and test catalog.
3. The stages document for Stage 1 / Stage 2 / Stage 3 / Stage 4 boundaries and backlog.
4. Existing code is evidence for status hints only. It is not a source for inventing requirements.

The 2026-11-14 medical TZ replaces the 2026-10-23 medical TZ. Suitable existing behavior is reused after verification. Existing medical data is retained. No requirement in this file is marked `IMPLEMENTED`, `VERIFIED`, or `OWNER ACCEPTED` merely because this ledger exists.

## Ledger conventions

Every requirement has a stable REQ-ID and the following fields:

- `Source`
- `Source section`
- `Type`: `ADD`, `CHANGE`, `REUSE`, `REMOVE-FROM-UI`, `VERIFY-FIX`, `EXCLUDE`, or `WORTHWHILE`
- `Requirement`
- `Acceptance`
- `Dependencies`
- `Delivery package`
- `Status`

Initial statuses in this package are limited to:

- `TO IMPLEMENT`
- `EXISTING - NEEDS VERIFICATION`
- `OUT OF STAGE 1`
- `OPEN QUESTION - NON-BLOCKING`

The word `required` describes the future product contract, not current implementation state. `WORTHWHILE` means desirable in the sources and not an acceptance blocker unless separately promoted by the owner.

## Count and delivery map

| Bucket | REQs | Meaning |
|---|---:|---|
| Stage 1A | 94 | Session card, complaints, questionnaire/profile, protocol/notes, call-10.11 fixes, and compatibility foundation |
| Stage 1B | 96 | Test engine, 12 generic types, 31 tests, causes/tags, root causes, result, effectiveness, recommendations |
| Stage 1C | 24 | Vitals, anthropometry, photos, files, audio delivery, avatar, PDF |
| Stage 2 | 25 | AI and automated-rule backlog, including named preparation inputs |
| Stage 3 | 22 | Client-facing, communication, payment, export, and voice backlog excluding separately counted source OQs |
| Stage 4 | 21 | Multi-master scale, analytics, technique library, apps, devices, and other methodologies |
| Open Questions | 18 | 13 catalog OQs plus 5 Stage 3 source OQs; none blocks starting Stage 1 development |
| **Total unique REQs** | **300** | Every ID in this file and the linked catalog is unique |

Catalog REQs and catalog OQs are defined in the linked test-catalog file. The package counts are disjoint: open questions are counted in the Open Questions bucket, not in a delivery-package bucket, while their proposed/default behavior remains linked to the applicable stage.

## Stage 1A - session, complaints, questionnaire, profile, and fixes

### Core principles

### REQ-MED-1A-CORE-001 - 2026-11-14 supersedes the 2026-10-23 medical TZ

- Source: Stage 1 TZ; requirements changelog
- Source section: `Преамбула`, paragraphs 4-6
- Type: `CHANGE`
- Requirement: Treat the 2026-11-14 medical TZ as the current medical source and the 2026-10-23 medical TZ as superseded.
- Acceptance: The canonical files identify the supersession and future implementation tasks reference these REQ IDs rather than re-auditing the old DOCX.
- Dependencies: None.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-CORE-002 - Reuse suitable existing behavior

- Source: Stage 1 TZ; 10.11 call fixes
- Source section: `Преамбула`, paragraph 5; `К. Исправления по созвону 10.11`, row 4
- Type: `REUSE`
- Requirement: Do not rebuild mechanisms that already comply with the new TZ. Keep suitable existing medical-profile, record, chat, and connection behavior after requirement-level verification.
- Acceptance: Each future implementation package records reuse or a gap; no existing behavior is called compliant without verification evidence.
- Dependencies: `REQ-MED-1A-CORE-001`.
- Delivery package: `1A`
- Status: `EXISTING - NEEDS VERIFICATION`

### REQ-MED-1A-CORE-003 - Store medical data as structured records

- Source: Stage 1 TZ
- Source section: `Принципы`, paragraph 8
- Type: `ADD`
- Requirement: Each complaint, test row, cause, and measurement is a structured record tied to the relevant session and master, not one general text blob.
- Acceptance: Future implementation can query and aggregate each item independently for agents, analytics, carryover, history, and deterministic counters.
- Dependencies: `REQ-MED-1A-CORE-004`; the canonical session model.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-CORE-004 - Bind every medical record to a master

- Source: Stage 1 TZ; stages document
- Source section: `Принципы`, paragraph 11; `Этап 4`, paragraph 87
- Type: `ADD`
- Requirement: Every medical record has a master association. The model must not make future multiple-master operation impossible; `master_id` is not the tenant boundary.
- Acceptance: A future implementation can identify the master for each session, complaint, test result, cause, measurement, photo, file, audio record, and related history without trusting request-supplied tenant identity.
- Dependencies: Organization ownership and authorization boundaries.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-CORE-005 - Owner/admin-editable medical dictionaries

- Source: Stage 1 TZ
- Source section: `Принципы`, paragraph 9
- Type: `ADD`
- Requirement: Authorized owner/admin users manage tests, test sets, cause/tag records, complaints, measurement norms, and pulse-wave types through CRM without code change or deployment.
- Acceptance: A permitted dictionary change is saved through a normal CRM control and is used by later sessions; no deployment is required for the business value change.
- Dependencies: Stage 1B catalog/tag engine; Stage 1C threshold/pulse-wave configuration.
- Delivery package: `1A / 1B / 1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-CORE-006 - Preserve historical data when fields leave the form

- Source: Stage 1 TZ
- Source section: `Принципы`, paragraph 10
- Type: `CHANGE`
- Requirement: Remove obsolete fields from active forms when the new model replaces them, but retain their stored historical values.
- Acceptance: A future implementation can read historical values after the new form is used; no destructive deletion is part of the Stage 1 model change.
- Dependencies: Compatibility requirements `REQ-MED-COMPAT-001` through `REQ-MED-COMPAT-015`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-CORE-007 - Desktop and tablet usability is mandatory

- Source: Stage 1 TZ; stages document
- Source section: `Принципы`, paragraph 13; Stage 1 goal and acceptance
- Type: `ADD`
- Requirement: CRM medical entry works on desktop and tablet. The Stage 1 acceptance path is not desktop-only.
- Acceptance: The package acceptance journeys run on desktop and tablet at the relevant surfaces; no backend-shaped or unusably narrow entry control remains on the acceptance path.
- Dependencies: `REQ-MED-1A-CORE-008`; the product UI rules.
- Delivery package: `1A / 1B / 1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-CORE-008 - Large controls and maximally one-click entry

- Source: Stage 1 TZ; test catalog
- Source section: `Принципы`, paragraph 13; catalog common rules
- Type: `ADD`
- Requirement: Test and session inputs use large, touch-friendly controls and one-click selection wherever the source specifies buttons, chips, or direct choices.
- Acceptance: A master can enter the Stage 1A and 1B acceptance journeys with tablet-sized controls; index, intensity, and other source-defined selections do not require typing when a direct control is specified.
- Dependencies: `REQ-MED-1A-CORE-007`.
- Delivery package: `1A / 1B / 1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-CORE-009 - Real-session completion is the final Stage 1 outcome

- Source: Stage 1 TZ; stages document
- Source section: `Преамбула`, paragraph 6; Stage 1 closure table
- Type: `ADD`
- Requirement: Final Stage 1 acceptance includes Женя conducting 2-3 real sessions completely in the system without notes in a phone.
- Acceptance: After independent agent-led acceptance of 1A, 1B, and 1C on staging, the final acceptance record names the real sessions and confirms end-to-end use of the new structures without a parallel phone-notes workflow. This is a practical usability confirmation, not a substitute for package QA.
- Dependencies: Stage 1A, 1B, and 1C package acceptance.
- Delivery package: `1A / 1B / 1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-CORE-010 - Owner acceptance is final confirmation, not exploratory QA

- Source: Current task instruction; stages document
- Source section: Stage 1 closure criterion
- Type: `CHANGE`
- Requirement: Owner acceptance confirms the result after agent-led verification; it is not a substitute for exploratory QA of broken CRUD, navigation, missing states, authorization, or obvious usability defects.
- Acceptance: The final handoff requests owner acceptance only after the package matrices and relevant user journeys have been executed and classified.
- Dependencies: Package acceptance matrices below; verification evidence.
- Delivery package: `1A / 1B / 1C`
- Status: `TO IMPLEMENT`

### Session card

### REQ-MED-1A-SESSION-001 - Exact session block order

- Source: Stage 1 TZ
- Source section: `А. Карточка сеанса`, table rows 23.2-23.11
- Type: `CHANGE`
- Requirement: The session card is ordered as: `0. Показатели до сеанса`; `1. Жалобы (боли)`; `2. Кинезиологические и мануальные тесты`; `3. Первопричина нарушений и причины нарушений`; `4. Пошаговый протокол сеанса`; `5. Субъективные ощущения клиента после сеанса`; `6. Визуальные изменения в осанке после сеанса`; `7. Результат после сеанса`; `8. Заметки терапевта`; `9. Эффективность проведённого сеанса`; `10. Рекомендации эксперта`.
- Acceptance: A new session opens with these blocks in this order and with these human labels. The unnumbered vitals block is the source table's pre-session block; the numbered sequence remains 1-10.
- Dependencies: Stage 1C vitals; Stage 1B test/result/effectiveness/recommendation blocks.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-SESSION-002 - Remove the general top pain scale

- Source: Stage 1 TZ
- Source section: `А. Карточка сеанса`, paragraph 25
- Type: `REMOVE-FROM-UI`
- Requirement: The active session form has no single general pain scale above the ordered blocks; pain is recorded through structured complaint rows.
- Acceptance: The old top-level scale is absent from the active form while historical stored data remains readable where required.
- Dependencies: `REQ-MED-1A-COMPLAINT-001`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-SESSION-003 - Remove the old objective-change field

- Source: Stage 1 TZ
- Source section: `А. Карточка сеанса`, paragraph 25
- Type: `REMOVE-FROM-UI`
- Requirement: The active session form has no field named `Объективные изменения после сеанса`.
- Acceptance: The old field is not offered in a new session; historical values remain readable where required by compatibility rules.
- Dependencies: `REQ-MED-1A-CORE-006`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-SESSION-004 - Reserve AI-version space in four blocks

- Source: Stage 1 TZ; stages document
- Source section: `А. Карточка сеанса`, paragraph 26; Stage 2 AI description
- Type: `ADD`
- Requirement: Blocks 3, 4, 7, and 10 expose a `Заполнить с ИИ` action and a place for a `Версия ИИ` column.
- Acceptance: The four blocks have a stable reserved presentation seam even while the action is disabled in Stage 1.
- Dependencies: `REQ-MED-1A-SESSION-005`; Stage 2 AI REQs.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-SESSION-005 - Disable AI fill in Stage 1

- Source: Stage 1 TZ
- Source section: `А. Карточка сеанса`, paragraph 26
- Type: `EXCLUDE`
- Requirement: `Заполнить с ИИ` is inactive throughout Stage 1.
- Acceptance: A user cannot invoke AI from these blocks in Stage 1; no AI request or hidden write occurs.
- Dependencies: `REQ-MED-1A-SESSION-004`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-SESSION-006 - Explain the future AI boundary

- Source: Stage 1 TZ
- Source section: `А. Карточка сеанса`, paragraph 26
- Type: `ADD`
- Requirement: The disabled action has a tooltip or adjacent text stating that AI will be connected at Stage 2, using human wording such as `подключим на этапе 2`.
- Acceptance: The reason for the disabled action is visible without developer knowledge.
- Dependencies: `REQ-MED-1A-SESSION-005`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-SESSION-007 - Disabled AI never changes user data

- Source: Stage 1 TZ
- Source section: `А. Карточка сеанса`, paragraph 26
- Type: `ADD`
- Requirement: An inactive AI action cannot modify text, structured values, statuses, or saved history.
- Acceptance: Clicking or attempting to activate the disabled action leaves all user-entered values and persisted state unchanged.
- Dependencies: `REQ-MED-1A-SESSION-005`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-SESSION-008 - Collapse long blocks

- Source: Stage 1 TZ
- Source section: `А. Карточка сеанса`, paragraph 28
- Type: `ADD`
- Requirement: Long blocks, especially tests and vitals, can be collapsed so a master does not have to scroll through the complete form at once.
- Acceptance: The state of collapsed and expanded blocks is usable on desktop and tablet; collapsing does not discard data.
- Dependencies: `REQ-MED-1A-CORE-007`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-SESSION-009 - Autosave on every change

- Source: Stage 1 TZ
- Source section: `Принципы`, paragraph 12; `А. Карточка сеанса`, paragraph 27
- Type: `ADD`
- Requirement: The session card saves on each change.
- Acceptance: A changed structured or text value is persisted without a separate manual save action, subject to visible error handling if saving fails.
- Dependencies: `REQ-MED-1A-CORE-003`; the canonical session model.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-SESSION-010 - Close and reopen without losing saved data

- Source: Stage 1 TZ
- Source section: `А. Карточка сеанса`, paragraph 29
- Type: `ADD`
- Requirement: Closing the tab or leaving the session after an autosave does not lose already saved data; reopening restores the draft.
- Acceptance: The package journey is `open -> edit -> wait for save -> close -> reopen`; the saved values are present.
- Dependencies: `REQ-MED-1A-SESSION-009`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-SESSION-011 - Draft and completed states

- Source: Stage 1 TZ
- Source section: `Принципы`, paragraph 12; current task instruction
- Type: `ADD`
- Requirement: A session distinguishes `черновик` from `завершён`.
- Acceptance: The state is visible and remains correct after reload and return to the client card.
- Dependencies: `REQ-MED-1A-SESSION-009`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-SESSION-012 - Complete a session explicitly

- Source: Stage 1 TZ
- Source section: `Принципы`, paragraph 12
- Type: `ADD`
- Requirement: The session has a `Завершить сеанс` action that fixes the current result state.
- Acceptance: The action changes the session from draft to completed and retains the entered result after reload.
- Dependencies: `REQ-MED-1A-SESSION-011`; result/effectiveness dependencies.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-SESSION-013 - Completed sessions remain editable

- Source: Stage 1 TZ
- Source section: `Принципы`, paragraph 12; current task instruction
- Type: `CHANGE`
- Requirement: A completed session can be edited by an authorized master.
- Acceptance: An authorized edit changes the selected data, preserves the completed session identity, and is included in the audit/history semantics.
- Dependencies: `REQ-MED-1A-SESSION-011`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-SESSION-014 - Mark edits to completed sessions

- Source: Stage 1 TZ
- Source section: `Принципы`, paragraph 12
- Type: `ADD`
- Requirement: After a completed session is changed, display `изменено` and the edit date.
- Acceptance: The marker appears after the edit and survives reload; an untouched completed session does not receive the marker.
- Dependencies: `REQ-MED-1A-SESSION-013`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### Complaints and pains

### REQ-MED-1A-COMPLAINT-001 - Complaints are atomic shared records

- Source: Stage 1 TZ
- Source section: `Принципы`, paragraph 8; `Б. Жалобы и боли`, paragraphs 30-33
- Type: `ADD`
- Requirement: Each complaint row is a structured record, and the same complaint reference is used by the client questionnaire and session card.
- Acceptance: A complaint can be selected, scored, carried into another session, and shown in history without parsing a free-text blob.
- Dependencies: `REQ-MED-1A-COMPLAINT-002`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-002 - Shared complaint dictionary groups

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, paragraph 41
- Type: `ADD`
- Requirement: One complaint dictionary is shared by questionnaire and session and has at least the groups `боль`, `дискомфорт`, and `состояние`.
- Acceptance: The same dictionary item can be chosen in both surfaces and appears under its configured group.
- Dependencies: `REQ-MED-1A-CORE-005`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-003 - Search complaints

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, table row 33.2
- Type: `ADD`
- Requirement: Complaint selection supports search.
- Acceptance: A master can find an existing complaint by its configured searchable name or synonym without scanning the whole dictionary.
- Dependencies: `REQ-MED-1A-COMPLAINT-002`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-004 - Add a custom complaint in one action

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, table row 33.2 and paragraph 41
- Type: `ADD`
- Requirement: A master can enter a complaint not yet in the dictionary and create its new name with one action.
- Acceptance: The new complaint is immediately usable in the current row and is available in the shared dictionary according to authorization rules.
- Dependencies: `REQ-MED-1A-COMPLAINT-002`; complaint authorization.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-005 - Multiple time-of-day rows for one complaint

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, paragraphs 31 and 35
- Type: `ADD`
- Requirement: One complaint can have multiple structured rows when intensity varies by time of day.
- Acceptance: The master can create three rows for `поясница` and keep each row's time and before/after values independent.
- Dependencies: `REQ-MED-1A-COMPLAINT-006`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-006 - Time-of-day values

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, table row 33.3
- Type: `ADD`
- Requirement: A complaint row's time-of-day value is one of `все`, `утро`, `день`, `вечер`, or `ночь`.
- Acceptance: The controls expose exactly these values and persist the selected value per row.
- Dependencies: `REQ-MED-1A-COMPLAINT-005`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-007 - Default time of day is all

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, table row 33.3
- Type: `ADD`
- Requirement: A newly created complaint row defaults to `все`.
- Acceptance: The master can leave the default unchanged and the stored row contains `все`.
- Dependencies: `REQ-MED-1A-COMPLAINT-006`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-008 - Constant frequency

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, table row 33.4
- Type: `ADD`
- Requirement: Periodicity supports the value `Постоянно`.
- Acceptance: A master can select `Постоянно` without entering a numeric frequency.
- Dependencies: `REQ-MED-1A-COMPLAINT-009`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-009 - Numeric periodicity and units

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, table row 33.4
- Type: `ADD`
- Requirement: Periodicity also supports a number from 1 to 999 plus one unit: `в час`, `в день`, `в неделю`, `в месяц`, or `в год`.
- Acceptance: Values outside 1-999 or outside the configured units are rejected; valid values are saved as structured fields.
- Dependencies: `REQ-MED-1A-COMPLAINT-008`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-010 - Optional periodicity

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, table row 33.4
- Type: `ADD`
- Requirement: Periodicity is optional.
- Acceptance: A complaint row can be saved without `Постоянно` or a numeric periodicity.
- Dependencies: `REQ-MED-1A-COMPLAINT-008`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-011 - Before intensity on a 0-10 control

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, table row 33.5
- Type: `ADD`
- Requirement: `Интенсивность до сеанса` uses large one-touch controls from 0 through 10.
- Acceptance: Every integer 0-10 is selectable and stored for the row without free-form numeric typing.
- Dependencies: `REQ-MED-1A-CORE-008`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-012 - After intensity on a 0-10 control

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, table row 33.6
- Type: `ADD`
- Requirement: `Интенсивность после сеанса` uses large one-touch controls from 0 through 10.
- Acceptance: Every integer 0-10 is selectable and stored independently from the before value.
- Dependencies: `REQ-MED-1A-COMPLAINT-011`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-013 - Add a complaint row

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, paragraph 35
- Type: `ADD`
- Requirement: `+ Жалоба` adds a new complaint row.
- Acceptance: The new row is empty except for defined defaults and does not overwrite existing rows.
- Dependencies: `REQ-MED-1A-COMPLAINT-001`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-014 - Add another time-of-day row

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, paragraph 35
- Type: `ADD`
- Requirement: `+ время суток` creates a copy of the row's complaint name with a different time-of-day value.
- Acceptance: The copied row retains the complaint identity but has an independently editable time and intensity.
- Dependencies: `REQ-MED-1A-COMPLAINT-005`; `REQ-MED-1A-COMPLAINT-006`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-015 - Special complaints text

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, paragraph 36
- Type: `ADD`
- Requirement: The session card has a separate `Особые жалобы` field below complaint rows.
- Acceptance: Free text in this field is stored independently of structured complaint rows and is included in the appropriate result/source flow.
- Dependencies: `REQ-MED-1A-COMPLAINT-001`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-016 - Seed the first session from questionnaire complaints

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, paragraph 37
- Type: `ADD`
- Requirement: On the first session, questionnaire complaints become session rows and the client's questionnaire score becomes `до сеанса`.
- Acceptance: The first session contains one row per seeded complaint with the client's score in before intensity.
- Dependencies: Questionnaire REQs; `REQ-MED-1A-COMPLAINT-001`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-017 - Mark questionnaire-seeded scores for clarification

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, paragraph 37
- Type: `ADD`
- Requirement: A questionnaire-seeded before score displays the marker `из анкеты, уточнить`.
- Acceptance: The marker is visible on the first session row until the master confirms or changes the score.
- Dependencies: `REQ-MED-1A-COMPLAINT-016`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-018 - Clear the questionnaire marker after master confirmation

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, paragraph 37
- Type: `CHANGE`
- Requirement: The `из анкеты, уточнить` marker disappears when the master confirms or changes the before score.
- Acceptance: Confirmation or edit clears only the marker, not the complaint row or its score.
- Dependencies: `REQ-MED-1A-COMPLAINT-017`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-019 - Carry complaints into the next session

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, paragraph 38
- Type: `ADD`
- Requirement: All rows from the previous session are preloaded into the next session.
- Acceptance: Each previous complaint row is present in the next session with its complaint identity and configured row fields.
- Dependencies: `REQ-MED-1A-COMPLAINT-001`; compatibility history.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-020 - Show previous after value in grey

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, paragraph 38
- Type: `ADD`
- Requirement: In a next session, the new before value is empty and the previous session's after value is shown next to it in grey.
- Acceptance: The master can enter the new before score without confusing it with the grey historical value.
- Dependencies: `REQ-MED-1A-COMPLAINT-019`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-021 - Represent a resolved complaint

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, paragraph 39
- Type: `ADD`
- Requirement: If `после = 0`, the next session shows the complaint row as `снята` and visually grey.
- Acceptance: The resolved state is derived from the prior after value and does not delete the complaint history.
- Dependencies: `REQ-MED-1A-COMPLAINT-019`; effectiveness counters.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-022 - Return a resolved complaint and preserve its history

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, paragraph 39
- Type: `ADD`
- Requirement: A grey `снята` row has a `Вернулась` action. Returning it makes the row active and preserves the history `была -> снята -> вернулась`.
- Acceptance: One action reactivates the complaint; the prior sessions remain readable and the return event is recorded.
- Dependencies: `REQ-MED-1A-COMPLAINT-021`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-COMPLAINT-023 - Complaint history and three-time-of-day acceptance example

- Source: Stage 1 TZ
- Source section: `Б. Жалобы и боли`, paragraphs 40 and 42
- Type: `ADD`
- Requirement: The client card shows complaint history by session, before, and after. The acceptance example is `поясница утром 8 / днём 3 / вечером 7` with after values entered separately.
- Acceptance: A master can create the three rows, complete after values, open the next session with prior after values beside empty before controls, resolve/return a row, and inspect the per-session history.
- Dependencies: `REQ-MED-1A-COMPLAINT-005` through `REQ-MED-1A-COMPLAINT-022`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### Questionnaire

### REQ-MED-1A-QUESTIONNAIRE-001 - Keep the current questionnaire overall

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraphs 183-184
- Type: `REUSE`
- Requirement: Keep the current client questionnaire overall because it is the low-friction entry point; make only the changes explicitly listed in this ledger.
- Acceptance: Existing compliant questionnaire sections remain available and the changed sections below are not replaced by an unrelated new flow.
- Dependencies: `REQ-MED-1A-CORE-002`.
- Delivery package: `1A`
- Status: `EXISTING - NEEDS VERIFICATION`

### REQ-MED-1A-QUESTIONNAIRE-002 - Accordion complaint groups from the shared dictionary

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraphs 185-188
- Type: `CHANGE`
- Requirement: Client complaints use an accordion/list from the shared complaint dictionary, grouped at least as `боль`, `дискомфорт`, and `состояние`.
- Acceptance: A client can open groups and select complaint items without seeing a backend-shaped dictionary.
- Dependencies: `REQ-MED-1A-COMPLAINT-002`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-QUESTIONNAIRE-003 - Multiple questionnaire complaints

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraphs 187-188
- Type: `CHANGE`
- Requirement: The client can select several complaints in one questionnaire.
- Acceptance: Selected complaints remain independently addressable and each receives its own score.
- Dependencies: `REQ-MED-1A-QUESTIONNAIRE-002`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-QUESTIONNAIRE-004 - Questionnaire complaint scores 0-10

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraph 188
- Type: `CHANGE`
- Requirement: Every selected questionnaire complaint has a 0-10 score using the large direct controls.
- Acceptance: Each selected complaint has a separate saved score from 0 through 10.
- Dependencies: `REQ-MED-1A-CORE-008`; `REQ-MED-1A-QUESTIONNAIRE-003`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-QUESTIONNAIRE-005 - Other complaint and free text

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraphs 189-190
- Type: `ADD`
- Requirement: The questionnaire offers `Другое` for a custom complaint and a free-text field `Опишите своими словами`.
- Acceptance: A client can provide a complaint not yet in the dictionary and explanatory text; the first session maps the free text to `Особые жалобы`.
- Dependencies: `REQ-MED-1A-COMPLAINT-004`; `REQ-MED-1A-COMPLAINT-015`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-QUESTIONNAIRE-006 - The client does not enter time of day

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraph 191
- Type: `REMOVE-FROM-UI`
- Requirement: The client questionnaire does not ask the client to fill time of day for complaints.
- Acceptance: Time-of-day controls are absent from the client questionnaire while the master can add them in the session card.
- Dependencies: `REQ-MED-1A-COMPLAINT-006`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-QUESTIONNAIRE-007 - The client does not enter periodicity

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraph 191
- Type: `REMOVE-FROM-UI`
- Requirement: The client questionnaire does not ask the client to fill complaint periodicity.
- Acceptance: Periodicity controls are absent from the client questionnaire while the master can use periodicity in the session card.
- Dependencies: `REQ-MED-1A-COMPLAINT-008` through `REQ-MED-1A-COMPLAINT-010`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-QUESTIONNAIRE-008 - Repeatable operation rows

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraphs 192-194
- Type: `CHANGE`
- Requirement: Operations use a repeatable list with `+ Добавить`; each row has `что именно` and `год`.
- Acceptance: A client can add zero, one, or many operation rows and save each row independently.
- Dependencies: Existing questionnaire persistence.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-QUESTIONNAIRE-009 - Optional operation year with human hint

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraph 195
- Type: `CHANGE`
- Requirement: Operation year is optional and has the human hint `если помните, можно примерно`.
- Acceptance: An operation row saves without a year; the hint is visible and does not turn the field into a blocker.
- Dependencies: `REQ-MED-1A-QUESTIONNAIRE-008`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-QUESTIONNAIRE-010 - Repeatable injury rows

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraphs 192-195
- Type: `CHANGE`
- Requirement: Injuries use the same repeatable `+ Добавить`, `что именно`, optional `год`, and `если помните, можно примерно` model as operations.
- Acceptance: A client can add zero, one, or many injury rows, including one without a year.
- Dependencies: `REQ-MED-1A-QUESTIONNAIRE-008`; `REQ-MED-1A-QUESTIONNAIRE-009`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-QUESTIONNAIRE-011 - Remove client anamnesis without deleting old values

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraph 196
- Type: `REMOVE-FROM-UI`
- Requirement: Remove `Анамнез` from the client questionnaire while retaining values already entered in historical data.
- Acceptance: New client questionnaires have no anamnesis field; old anamnesis remains readable through the compatibility path.
- Dependencies: `REQ-MED-1A-PROFILE-002`; `REQ-MED-COMPAT-009`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-QUESTIONNAIRE-012 - Preserve unchanged questionnaire blocks

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraph 197
- Type: `REUSE`
- Requirement: Keep goals, chronic diseases, medicines, supplements, and analysis upload behavior unless another requirement explicitly changes it.
- Acceptance: These fields remain usable and their existing data path is not removed by the questionnaire changes.
- Dependencies: `REQ-MED-1A-QUESTIONNAIRE-001`.
- Delivery package: `1A`
- Status: `EXISTING - NEEDS VERIFICATION`

### REQ-MED-1A-QUESTIONNAIRE-013 - Partial questionnaire save

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraph 198
- Type: `REUSE`
- Requirement: A client can complete the questionnaire in parts and previously entered values are saved.
- Acceptance: Leaving and returning to a partially completed questionnaire restores saved values without requiring completion of optional fields.
- Dependencies: Existing questionnaire persistence; `REQ-MED-1A-QUESTIONNAIRE-012`.
- Delivery package: `1A`
- Status: `EXISTING - NEEDS VERIFICATION`

### REQ-MED-1A-QUESTIONNAIRE-014 - Preserve the current reminder mechanism

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraph 198
- Type: `REUSE`
- Requirement: Keep the current soft reminder behavior for unfinished questionnaires if it already complies with the source requirements.
- Acceptance: The reminder remains non-destructive and does not turn the optional year or master-only anamnesis into client blockers.
- Dependencies: `REQ-MED-1A-QUESTIONNAIRE-013`.
- Delivery package: `1A`
- Status: `EXISTING - NEEDS VERIFICATION`

### Master medical profile

### REQ-MED-1A-PROFILE-001 - Master sees and can correct client data

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraph 200
- Type: `CHANGE`
- Requirement: The master sees all data entered by the client and can supplement or correct it through the CRM.
- Acceptance: A permitted master can open the client medical profile, edit the relevant fields, save, and see the corrected values after reload.
- Dependencies: `REQ-MED-1A-CORE-004`; authorization.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-PROFILE-002 - Master-only anamnesis field

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraph 201
- Type: `ADD`
- Requirement: Anamnesis is a separate master-only text field with the placeholder `когда началось, что предшествовало, что пробовали, связь с травмами и операциями`.
- Acceptance: The master can save and edit this field; the placeholder is visible when empty; it is distinct from the client questionnaire.
- Dependencies: `REQ-MED-1A-PROFILE-003`; `REQ-MED-COMPAT-009`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-PROFILE-003 - Hide master anamnesis from client in Stage 1

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraph 201
- Type: `REMOVE-FROM-UI`
- Requirement: The client does not see master anamnesis in Stage 1. Client visibility is deferred to Stage 3.
- Acceptance: Client-facing questionnaire/profile views contain no master anamnesis value or field in Stage 1.
- Dependencies: `REQ-MED-1A-PROFILE-002`; Stage 3 backlog.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-PROFILE-004 - Anthropometry and indicator history in the profile

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraphs 202 and 155-166
- Type: `ADD`
- Requirement: The master profile exposes anthropometry and the history of session indicators.
- Acceptance: The client card links to history by date/session and the anthropometry history; detailed field acceptance is in Stage 1C.
- Dependencies: Stage 1C vitals and anthropometry REQs.
- Delivery package: `1A / 1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-PROFILE-005 - Avatar in the client-card header

- Source: Stage 1 TZ
- Source section: `З. Медпрофиль и анкета клиента`, paragraph 203; `И.`, paragraphs 225-229
- Type: `ADD`
- Requirement: The client-card header displays the configured avatar and the required safety markers.
- Acceptance: The header shows the avatar according to `REQ-MED-1C-AVATAR-001` and the persistent Test 18 marker according to `REQ-MED-1B-SPECIAL-004`.
- Dependencies: Stage 1B Test 18; Stage 1C avatar.
- Delivery package: `1A / 1B / 1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-PROFILE-006 - Keep profile changes within the shared medical model

- Source: Stage 1 TZ
- Source section: `Преамбула`, paragraphs 4-6; `З.`, paragraphs 199-203
- Type: `CHANGE`
- Requirement: Do not continue the obsolete intermediate profile as an alternative authority. The new profile must reuse compatible data and write to the canonical model.
- Acceptance: Future profile implementation has one authoritative representation for new records and a readable compatibility path for old values.
- Dependencies: `REQ-MED-1A-CORE-001`; compatibility group.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### Protocol and notes

### REQ-MED-1A-PROTOCOL-001 - Step-by-step protocol

- Source: Stage 1 TZ
- Source section: `А.`, table row 23.6; current task instruction
- Type: `CHANGE`
- Requirement: Protocol uses a repeatable step model with `+ Шаг` and visible labels `Шаг 1`, `Шаг 2`, and so on. Each step has its own text.
- Acceptance: A master can add, edit, reorder or remove steps through the supported control model and each step persists independently.
- Dependencies: `REQ-MED-1A-CORE-003`; session autosave.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-PROTOCOL-002 - Do not leave the monolithic protocol as the only authority

- Source: Stage 1 TZ; current task instruction
- Source section: `А.`, table row 23.6; protocol requirement
- Type: `CHANGE`
- Requirement: The old monolithic protocol text must not remain the only authoritative representation for new sessions.
- Acceptance: New sessions use structured protocol steps; old monolithic values remain readable through compatibility where required.
- Dependencies: `REQ-MED-1A-PROTOCOL-001`; `REQ-MED-COMPAT-010` and `REQ-MED-COMPAT-012`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-NOTES-001 - Therapist notes are a separate master-only block

- Source: Stage 1 TZ
- Source section: `А.`, table row 23.10; current task instruction
- Type: `ADD`
- Requirement: `Заметки терапевта` is a separate text block visible only to the master.
- Acceptance: An authorized master can save and edit notes; a client cannot see them in Stage 1 surfaces.
- Dependencies: Session authorization.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-1A-NOTES-002 - Therapist notes are excluded from the PDF

- Source: Stage 1 TZ
- Source section: `И. Скачать анкету клиента`, paragraph 234
- Type: `CHANGE`
- Requirement: Therapist notes do not appear in the Stage 1 client questionnaire/card PDF.
- Acceptance: A generated PDF contains no therapist-note text, including notes from completed sessions.
- Dependencies: `REQ-MED-1C-PDF-002`.
- Delivery package: `1A / 1C`
- Status: `TO IMPLEMENT`

### 10.11 fixes

### REQ-MED-1A-CALL-001 - Persist the master's timezone

- Source: Stage 1 TZ
- Source section: `К. Исправления по созвону 10.11`, table row 242.2
- Type: `VERIFY-FIX`
- Requirement: The master's IANA timezone is saved as the authoritative display context for the master.
- Acceptance: A master setting survives reload and is used by the master-facing schedule/session time presentation.
- Dependencies: Existing specialist/timezone model.
- Delivery package: `1A`
- Status: `EXISTING - NEEDS VERIFICATION`

### REQ-MED-1A-CALL-002 - Persist the master's working hours

- Source: Stage 1 TZ
- Source section: `К.`, table row 242.2
- Type: `VERIFY-FIX`
- Requirement: The master's working hours are saved and remain the configured schedule source.
- Acceptance: Saved working hours survive reload and are used when availability is calculated.
- Dependencies: `REQ-MED-1A-CALL-001`.
- Delivery package: `1A`
- Status: `EXISTING - NEEDS VERIFICATION`

### REQ-MED-1A-CALL-003 - Availability uses the correct timezone

- Source: Stage 1 TZ
- Source section: `К.`, table row 242.2
- Type: `VERIFY-FIX`
- Requirement: Availability calculation uses the master's saved timezone and working hours without accidental offset or random day-off behavior.
- Acceptance: A configured working window produces slots on the intended local days and does not shift because of an unrelated default timezone.
- Dependencies: `REQ-MED-1A-CALL-001`; `REQ-MED-1A-CALL-002`.
- Delivery package: `1A`
- Status: `EXISTING - NEEDS VERIFICATION`

### REQ-MED-1A-CALL-004 - Show client slots in the client's IANA timezone

- Source: Stage 1 TZ; architecture timezone requirements
- Source section: `К.`, table row 242.2
- Type: `VERIFY-FIX`
- Requirement: The client sees available slots converted to the client's selected IANA timezone with the label `по вашему времени`.
- Acceptance: The same instant is presented in the client's timezone exactly once and the label is visible at the slot surface.
- Dependencies: Existing client timezone preference; `REQ-MED-1A-CALL-007`.
- Delivery package: `1A`
- Status: `EXISTING - NEEDS VERIFICATION`

### REQ-MED-1A-CALL-005 - Show the master time in the master's timezone

- Source: Stage 1 TZ
- Source section: `К.`, table row 242.2
- Type: `VERIFY-FIX`
- Requirement: The master sees booking/availability times in the master's configured timezone.
- Acceptance: The master-facing time and the client-facing time represent the same instant in their respective zones.
- Dependencies: `REQ-MED-1A-CALL-001`; `REQ-MED-1A-CALL-004`.
- Delivery package: `1A`
- Status: `EXISTING - NEEDS VERIFICATION`

### REQ-MED-1A-CALL-006 - Convert a time exactly once

- Source: Current task instruction; Stage 1 TZ
- Source section: `К.`, table row 242.2
- Type: `VERIFY-FIX`
- Requirement: Timezone conversion happens exactly once at the display boundary; no double conversion changes the instant.
- Acceptance: A known instant round-trips through master and client displays with the expected local values and no second offset.
- Dependencies: `REQ-MED-1A-CALL-004`; `REQ-MED-1A-CALL-005`.
- Delivery package: `1A`
- Status: `EXISTING - NEEDS VERIFICATION`

### REQ-MED-1A-CALL-007 - Notify the client when AI help is returned

- Source: Stage 1 TZ
- Source section: `К.`, table row 242.3
- Type: `VERIFY-FIX`
- Requirement: When the specialist clicks `Вернуть ИИ-помощника`, the client receives a system message such as `Специалист вышел из диалога, дальше отвечает помощник`.
- Acceptance: The handoff action produces the client-visible system message through the existing Telegram/chat path and does not require a second manual client action.
- Dependencies: Existing chat/companion handoff.
- Delivery package: `1A`
- Status: `EXISTING - NEEDS VERIFICATION`

### REQ-MED-1A-CALL-008 - Explain analytics without redesigning it

- Source: Stage 1 TZ
- Source section: `К.`, table row 242.4
- Type: `VERIFY-FIX`
- Requirement: Do not redesign the analytics console in Stage 1. Add a short human one-page instruction explaining what to fill, groups/categories/metrics, and how the report/export works.
- Acceptance: The instruction is available to Женя; no analytics redesign is included in Stage 1; future analytics redesign waits for real session data.
- Dependencies: Existing analytics console.
- Delivery package: `1A`
- Status: `EXISTING - NEEDS VERIFICATION`

### Compatibility and migration contract

### REQ-MED-COMPAT-001 - Retain old sessions

- Source: Stage 1 TZ; current task instruction
- Source section: `Принципы`, paragraph 10; compatibility requirements
- Type: `CHANGE`
- Requirement: Old sessions remain stored and readable.
- Acceptance: New UI paths do not require destructive conversion before old sessions can be opened or referenced.
- Dependencies: PostgreSQL implementation verification later.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-COMPAT-002 - Retain old complaints

- Source: Stage 1 TZ; current task instruction
- Source section: `Принципы`, paragraph 10; compatibility requirements
- Type: `CHANGE`
- Requirement: Old complaint records and their historical values remain stored and readable.
- Acceptance: Complaint history from old sessions remains available after the new complaint model is introduced.
- Dependencies: `REQ-MED-1A-COMPLAINT-001`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-COMPAT-003 - Retain old tests

- Source: Current task instruction; test catalog
- Source section: compatibility requirements; catalog history behavior
- Type: `CHANGE`
- Requirement: Old test records remain stored and readable even when new sessions use the canonical catalog model.
- Acceptance: A historical test result does not disappear or crash the new session/client views.
- Dependencies: Stage 1B catalog.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-COMPAT-004 - Retain old cause references

- Source: Current task instruction; Stage 1 TZ
- Source section: compatibility requirements; `Г. База тегов-причин`
- Type: `CHANGE`
- Requirement: Old cause/tag references remain readable and are not silently dropped when the shared cause DB is introduced.
- Acceptance: Historical sessions still display a meaningful cause reference, including a retired or merged tag projection where applicable.
- Dependencies: Stage 1B cause DB and merge lifecycle.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-COMPAT-005 - Retain old root causes

- Source: Current task instruction; Stage 1 TZ
- Source section: compatibility requirements; `Д.`, block 3
- Type: `CHANGE`
- Requirement: Old root-cause selections and text remain readable.
- Acceptance: Historical root-cause data remains available even if the new session offers structured selection and relations.
- Dependencies: `REQ-MED-1B-ROOT-001` through `REQ-MED-1B-ROOT-003`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-COMPAT-006 - Retain old photos

- Source: Current task instruction; Stage 1 TZ
- Source section: compatibility requirements; `Ж. Фото`
- Type: `CHANGE`
- Requirement: Old medical photos remain stored and accessible under the protected photo lifecycle.
- Acceptance: New photo views do not require deleting or recompressing old originals.
- Dependencies: Stage 1C photo requirements.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-COMPAT-007 - Retain old files

- Source: Current task instruction; Stage 1 TZ
- Source section: compatibility requirements; `И. Файлы`
- Type: `CHANGE`
- Requirement: Old medical files remain stored and readable in the new file/category projection.
- Acceptance: Historical files can be opened/downloaded according to the protected storage policy after category split.
- Dependencies: Stage 1C files.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-COMPAT-008 - Retain old audio

- Source: Current task instruction; Stage 1 TZ
- Source section: compatibility requirements; `И. Аудиозаписи`
- Type: `REUSE`
- Requirement: Old private audio remains stored and usable through the supported audio lifecycle.
- Acceptance: Existing audio is not exposed publicly or removed by the new client-send behavior.
- Dependencies: Stage 1C audio.
- Delivery package: `1A`
- Status: `EXISTING - NEEDS VERIFICATION`

### REQ-MED-COMPAT-009 - Retain old anamnesis

- Source: Stage 1 TZ; current task instruction
- Source section: `З.`, paragraph 196; compatibility requirements
- Type: `CHANGE`
- Requirement: Old anamnesis values remain stored and readable after the client field is removed and the master-only field is introduced.
- Acceptance: Existing anamnesis can be reviewed by the master and is not displayed to the client in Stage 1.
- Dependencies: `REQ-MED-1A-PROFILE-002`; `REQ-MED-1A-PROFILE-003`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-COMPAT-010 - Keep hidden legacy fields historically readable

- Source: Current task instruction
- Source section: compatibility requirements
- Type: `CHANGE`
- Requirement: Legacy fields removed from active forms remain historically readable where required by their source/history role.
- Acceptance: Removing a field from the form does not make its old values inaccessible or reinterpret them as new values without an explicit mapping.
- Dependencies: `REQ-MED-1A-CORE-006`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-COMPAT-011 - Migration is non-destructive

- Source: Current task instruction
- Source section: compatibility requirements
- Type: `CHANGE`
- Requirement: Any future migration/import path preserves source records and references; no destructive reset, truncate, or overwrite is part of this contract.
- Acceptance: The implementation plan for migration is separate from this documentation task and includes a recoverable/non-destructive path.
- Dependencies: `REQ-MED-COMPAT-001` through `REQ-MED-COMPAT-010`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-COMPAT-012 - New sessions use the canonical new model

- Source: Stage 1 TZ; current task instruction
- Source section: `Преамбула`, paragraphs 4-6; compatibility requirements
- Type: `CHANGE`
- Requirement: New sessions write the canonical structured session, complaint, test, cause, result, and measurement model.
- Acceptance: A new session does not write only legacy blobs and can participate in carryover, counters, result assembly, and later Stage 2 processing.
- Dependencies: `REQ-MED-1A-CORE-003`; Stage 1B and 1C REQs.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-COMPAT-013 - Old records must not crash the new UI

- Source: Current task instruction; Stage 1 TZ
- Source section: compatibility requirements; `Преамбула`, paragraph 5
- Type: `CHANGE`
- Requirement: New session/profile/client-card views handle old records without an exception or broken state.
- Acceptance: Old sessions, complaints, tests, causes, root causes, photos, files, audio, and anamnesis can be opened or safely represented with a visible historical fallback.
- Dependencies: `REQ-MED-COMPAT-001` through `REQ-MED-COMPAT-010`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-COMPAT-014 - Migration/import must not duplicate history

- Source: Current task instruction
- Source section: compatibility requirements
- Type: `CHANGE`
- Requirement: A future migration/import does not create duplicate session history, complaints, test results, causes, files, photos, audio, or anamnesis records on retry.
- Acceptance: Duplicate detection/idempotency is proven in the eventual implementation; documentation-only work does not design or execute the migration.
- Dependencies: `REQ-MED-COMPAT-011`; PostgreSQL evidence.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

### REQ-MED-COMPAT-015 - PostgreSQL verification is mandatory later

- Source: Repository authority; current task instruction
- Source section: compatibility requirements; repository data rules
- Type: `VERIFY-FIX`
- Requirement: Eventual compatibility implementation is verified on staging with PostgreSQL for constraints, native types, timestamps, indexes, upsert/idempotency, transactions, and related historical behavior.
- Acceptance: A future package reports application and database evidence from staging with PostgreSQL; SQLite is not used for testing and CI does not replace staging acceptance.
- Dependencies: `REQ-MED-COMPAT-011` through `REQ-MED-COMPAT-014`.
- Delivery package: `1A`
- Status: `TO IMPLEMENT`

## Stage 1C - vitals, anthropometry, photos, files, audio, avatar, PDF

### Vitals

### REQ-MED-1C-VITALS-001 - Structured vitals and exact formulas

- Source: Stage 1 TZ
- Source section: `Е. Показатели и антропометрия`, table 147
- Type: `ADD`
- Requirement: Store optional `SYS` integer, `DIA` integer, `PD = SYS - DIA`, `MAP = DIA + PD / 3` rounded to an integer, pulse integer, `PI` decimal from 0.02 through 20, `SpO2` integer, configurable pulse-wave type, and pulse-wave device photo.
- Acceptance: `138/88` produces `PD 50` and `MAP 105`; all fields remain structured and independently readable by session/date.
- Dependencies: `REQ-MED-1A-CORE-005`; session block order.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-VITALS-002 - Before and explicitly enabled after measurements

- Source: Stage 1 TZ
- Source section: `Е.`, paragraphs 145-146
- Type: `ADD`
- Requirement: A before measurement is available in every session; after measurement is disabled until explicitly enabled by the master; every field remains optional.
- Acceptance: A session can have before only, before plus after, or no entered optional value, without validation requiring an absent measurement.
- Dependencies: `REQ-MED-1A-SESSION-001`.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-VITALS-003 - Tablet numeric input

- Source: Stage 1 TZ
- Source section: `Е.`, paragraph 148
- Type: `ADD`
- Requirement: Numeric vitals open a tablet-friendly numeric keyboard and do not require a desktop-only input path.
- Acceptance: SYS, DIA, pulse, PI, and SpO2 can be entered using numeric controls on a tablet; decimal PI accepts one or two decimal places.
- Dependencies: `REQ-MED-1A-CORE-007`; `REQ-MED-1A-CORE-008`.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-VITALS-004 - Configurable threshold colors

- Source: Stage 1 TZ
- Source section: `Е.`, paragraphs 149-152
- Type: `ADD`
- Requirement: Authorized owner/admin users configure approved thresholds. Values are displayed as green normal, yellow attention, or red flag. The source examples include pressure from 180/110 as red, pulse below 40 or above 100 as red, SpO2 below 90 as red, and SpO2 93-94 as yellow.
- Acceptance: Threshold edits are possible through configuration without code/deploy; the source examples can be represented; no threshold outside approved source/config is invented by this ledger.
- Dependencies: `REQ-MED-1A-CORE-005`.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-VITALS-005 - Master-only red session marker

- Source: Stage 1 TZ
- Source section: `Е.`, paragraph 153
- Type: `ADD`
- Requirement: A red threshold result adds the session marker `Показатель вне допустимого диапазона`. This is a master-facing marker; the client does not see the clinical red marker in Stage 1.
- Acceptance: A red result creates the session marker, a non-red result does not, and the client-facing Stage 1 view contains no clinical red marker.
- Dependencies: `REQ-MED-1C-VITALS-004`; session header.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-VITALS-006 - Vitals history and source examples

- Source: Stage 1 TZ
- Source section: `Е.`, paragraphs 154 and 162-166
- Type: `ADD`
- Requirement: The client card shows mandatory indicator history by session date with before and after values. A graph is an optional `WORTHWHILE` improvement, not a required Stage 1 capability. `SpO2 89` is red. History remains readable after reload.
- Acceptance: The mandatory table history is implemented and the examples produce the specified values/colors; graph delivery is reported separately as `WORTHWHILE` and does not block acceptance.
- Dependencies: `REQ-MED-1C-VITALS-001` through `REQ-MED-1C-VITALS-005`.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### Anthropometry

### REQ-MED-1C-ANTHRO-001 - Structured anthropometry measurements

- Source: Stage 1 TZ
- Source section: `Е. Антропометрия`, paragraphs 155-160
- Type: `ADD`
- Requirement: `+ Замер` creates a dated measurement with height cm, weight kg, BMI, impedance index, waist, hips, chest, right thigh, left thigh, right shoulder/arm, and left shoulder/arm, preserving the source wording `правое плечо` and `левое плечо`.
- Acceptance: A master can create multiple dated measurements and each field is stored as a structured value with the specified unit/side.
- Dependencies: `REQ-MED-1A-PROFILE-004`.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-ANTHRO-002 - BMI, history, and previous-height prefill

- Source: Stage 1 TZ
- Source section: `Е. Антропометрия`, paragraphs 158 and 161-166
- Type: `ADD`
- Requirement: BMI is `weight / (height in meters)^2` rounded to one decimal; the history is a table; a new measurement prefills height from the previous measurement. `175 cm + 70 kg` produces BMI `22.9`.
- Acceptance: The formula and one-decimal presentation match the example, previous height is prefilled, and prior measurements remain visible.
- Dependencies: `REQ-MED-1C-ANTHRO-001`.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### Photos

### REQ-MED-1C-PHOTO-001 - Six semantic session photo areas

- Source: Stage 1 TZ
- Source section: `Ж. Фото`, paragraphs 167-170
- Type: `ADD`
- Requirement: Each session photo has a plane `front`, `side`, or `back` and a moment `before` or `after`, producing six semantic areas: front-before, front-after, side-before, side-after, back-before, back-after.
- Acceptance: A session can contain all six areas and each stored photo is linked to its session, plane, and moment.
- Dependencies: `REQ-MED-1A-CORE-003`.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-PHOTO-002 - Tablet camera, upload, preview, and controlled delete

- Source: Stage 1 TZ
- Source section: `Ж. Фото`, paragraphs 169-171
- Type: `ADD`
- Requirement: The master can take a photo with a tablet camera or upload a file, see a large preview, and delete it through a controlled authorized action. Multiple photos are allowed in each semantic area.
- Acceptance: Each of the six areas accepts zero, one, or many photos; preview and delete do not alter other areas or session records.
- Dependencies: `REQ-MED-1C-PHOTO-001`; protected storage authorization.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-PHOTO-003 - Client-card photo tab

- Source: Stage 1 TZ
- Source section: `Ж. Фото`, paragraphs 173-178
- Type: `ADD`
- Requirement: The client card aggregates photos from sessions, groups them by session, labels them `До 1`, `После 1`, `До 2`, `После 2`, and shows plane and date. The tab has a plane filter. Stage 1 photos are master-only.
- Acceptance: Photos from multiple sessions are grouped and labeled correctly; filtering by plane changes the view without changing stored files; a client cannot see the Stage 1 photo tab data.
- Dependencies: `REQ-MED-1C-PHOTO-001`; `REQ-MED-1C-PHOTO-002`.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-PHOTO-004 - Original photo download

- Source: Stage 1 TZ
- Source section: `Ж. Фото`, paragraphs 176-182
- Type: `ADD`
- Requirement: Originals are stored without compression. The master can download one photo or all photos as an archive.
- Acceptance: The downloaded single original matches the stored original by size and content rather than a recompressed derivative; the archive contains authorized originals.
- Dependencies: Private storage and file authorization.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-PHOTO-005 - Side-by-side comparison is worthwhile, not blocking

- Source: Stage 1 TZ
- Source section: `Ж. Фото`, paragraph 172
- Type: `WORTHWHILE`
- Requirement: A same-plane before/after side-by-side view may be added. It is desirable and not an acceptance blocker unless the owner promotes it.
- Acceptance: If implemented, the view uses the semantic plane/moment links and does not replace original download; otherwise the package report explicitly marks it as `WORTHWHILE`.
- Dependencies: `REQ-MED-1C-PHOTO-001` through `REQ-MED-1C-PHOTO-004`.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### Files

### REQ-MED-1C-FILES-001 - Split medical and general file categories

- Source: Stage 1 TZ
- Source section: `И. Файлы`, paragraphs 208-213
- Type: `CHANGE`
- Requirement: The client card has two explicit sections: `МРТ, УЗИ и выписки` for analyses, conclusions, extracts, images/scans, including medical uploads from the questionnaire; and `Файлы` for other materials, including short videos.
- Acceptance: A questionnaire medical upload lands in `МРТ, УЗИ и выписки`; an ordinary file or short walking video lands in `Файлы`; categories remain distinguishable after reload.
- Dependencies: `REQ-MED-1A-QUESTIONNAIRE-012`; protected file storage.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-FILES-002 - Common file lifecycle and metadata

- Source: Stage 1 TZ
- Source section: `И. Файлы`, paragraph 213
- Type: `ADD`
- Requirement: The master can upload files to either category and authorized users can preview, download, and use the supported delete/lifecycle action. Each file shows upload date and uploader.
- Acceptance: Metadata survives reload; preview/download use private authorization; actions are separated by category and uploader/date are visible.
- Dependencies: `REQ-MED-1C-FILES-001`.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-FILES-003 - Short-video guidance without inventing hidden policy

- Source: Stage 1 TZ; current task instruction
- Source section: `И. Файлы`, paragraph 212
- Type: `ADD`
- Requirement: Support short videos such as walking videos. The source guidance is approximately 30 seconds and about 50 MB. The source response checklist also asks for audio/video/MRI limits. Final limits are owner/admin-facing configuration or policy; no hidden fixed limit is invented in this ledger.
- Acceptance: The implementation exposes any final limit through the authoritative configuration/policy surface and reports the source guidance separately from approved values.
- Dependencies: `REQ-MED-1A-CORE-005`; storage/provider policy.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-FILES-004 - Protected delete/lifecycle policy

- Source: Stage 1 TZ; current task instruction
- Source section: `И. Файлы`, paragraph 213; file retention instruction
- Type: `CHANGE`
- Requirement: The source requests delete, but physical deletion is not documented as mandatory where it conflicts with protected medical retention or legal policy. Provide the supported authorized delete/lifecycle behavior subject to the authoritative retention/security policy.
- Acceptance: A future implementation documents whether delete is logical, physical, or restricted for each category; no unsafe physical deletion is silently assumed.
- Dependencies: Protected medical retention/security policy.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### Audio

### REQ-MED-1C-AUDIO-001 - Reuse the private audio foundation

- Source: Stage 1 TZ; 23.10 reference
- Source section: `И. Аудиозаписи`, paragraphs 214-219
- Type: `REUSE`
- Requirement: Reuse the current private audio storage foundation if it complies with the source contract.
- Acceptance: Existing audio remains private, readable, and available to the master; no public URL is introduced.
- Dependencies: `REQ-MED-COMPAT-008`.
- Delivery package: `1C`
- Status: `EXISTING - NEEDS VERIFICATION`

### REQ-MED-1C-AUDIO-002 - Client-card audio section

- Source: Stage 1 TZ
- Source section: `И. Аудиозаписи`, paragraphs 214-219
- Type: `ADD`
- Requirement: The client card has a separate audio section with title and file, play, download, supported delete/lifecycle action, and private storage.
- Acceptance: The master can create, play, and download an audio record; its title and private lifecycle are visible.
- Dependencies: `REQ-MED-1C-AUDIO-001`.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-AUDIO-003 - Explicit Telegram audio delivery

- Source: Stage 1 TZ
- Source section: `И. Аудиозаписи`, paragraphs 220-223
- Type: `ADD`
- Requirement: `Отправить клиенту` sends an audio file through the Telegram bot, then records `отправлено клиенту` and the sent date. Unsent audio is invisible to the client.
- Acceptance: No audio is sent without the explicit action; sent status/date appear after successful delivery; a client cannot access unsent audio.
- Dependencies: Telegram channel adapter; `REQ-MED-1C-AUDIO-002`.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-AUDIO-004 - Safe large-audio fallback

- Source: Stage 1 TZ; current task instruction
- Source section: `И. Аудиозаписи`, paragraph 224
- Type: `ADD`
- Requirement: If Telegram rejects the file because of size, use a safe limited-lifetime protected link or equivalent. Never expose a permanent public medical URL.
- Acceptance: A size rejection produces a protected, time-limited fallback with access control and audit/status; a permanent public URL is never generated.
- Dependencies: `REQ-MED-1C-AUDIO-003`; private storage/security policy.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### Avatar

### REQ-MED-1C-AVATAR-001 - Avatar source, fallback, upload, and surfaces

- Source: Stage 1 TZ
- Source section: `И. Аватар клиента`, paragraphs 225-229
- Type: `ADD`
- Requirement: Use the Telegram photo by default when available, otherwise initials. The client may optionally upload a photo, the master may upload/replace it, and the avatar is visible in the client list, booking journal, and client-card header.
- Acceptance: Each fallback/upload path produces the expected avatar on all three surfaces; replacing the avatar does not change medical history.
- Dependencies: Telegram identity/media evidence; `REQ-MED-1A-PROFILE-005`.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### PDF

### REQ-MED-1C-PDF-001 - Master-only questionnaire/card PDF contents

- Source: Stage 1 TZ
- Source section: `И. Скачать анкету клиента`, paragraphs 230-233
- Type: `ADD`
- Requirement: A master-only button downloads a PDF containing personal data, request/contact date per source, complaints, goals, session list/dates, each session result, effectiveness numbers, and recommendations.
- Acceptance: A PDF generated from a client with sessions contains all listed sections and is available only to an authorized master.
- Dependencies: Session/result/effectiveness/recommendation history; file access authorization.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

### REQ-MED-1C-PDF-002 - PDF exclusions and Stage 1 export boundary

- Source: Stage 1 TZ; stages document; current task instruction
- Source section: `И. Скачать анкету клиента`, paragraph 234; Stage 3 exports
- Type: `EXCLUDE`
- Requirement: The Stage 1 PDF excludes therapist notes, audio, and photos. Word export, Excel questionnaire export, and medical email sending are Stage 3, not Stage 1.
- Acceptance: The Stage 1 PDF contains none of the excluded data; no Word/Excel/email feature is implemented under this Stage 1 package.
- Dependencies: `REQ-MED-1A-NOTES-002`; Stage 3 backlog.
- Delivery package: `1C`
- Status: `TO IMPLEMENT`

## Stage 2 backlog - out of Stage 1

All requirements in this section have status `OUT OF STAGE 1`. They are preserved so a later agent can implement them without reopening the 2026-11-14 DOCX. They do not block starting or accepting Stage 1.

### AI agents and automated rules

### REQ-MED-2-AI-001 - Pre-session agent

- Source: Stages document
- Source section: `Этап 2. ИИ-агенты`, paragraph 30
- Type: `ADD`
- Requirement: A pre-session agent may use complaints, pains, and tests to propose causes, a root cause, and a short protocol.
- Acceptance: Deferred to a dedicated Stage 2 TZ with human review and no automatic clinical fact promotion.
- Dependencies: Canonical Stage 1 structured data; approved AI boundary.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-002 - AI version column and source provenance

- Source: Stages document; Stage 1 TZ
- Source section: `Этап 2`, paragraph 30; Stage 1 AI-version seam
- Type: `ADD`
- Requirement: Stage 2 supplies an AI version next to the relevant medical fields and preserves the distinction between AI suggestion and specialist-confirmed data.
- Acceptance: Deferred; Stage 1 only reserves the presentation seam and keeps the action disabled.
- Dependencies: `REQ-MED-1A-SESSION-004`; structured Stage 1 model.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-003 - Accept AI suggestion

- Source: Stages document
- Source section: `Этап 2`, paragraph 30
- Type: `ADD`
- Requirement: The master can explicitly accept an AI proposal.
- Acceptance: Deferred; acceptance must be explicit, auditable, and distinct from generation.
- Dependencies: `REQ-MED-2-AI-001`; Stage 2 AI governance.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-004 - Reject AI suggestion

- Source: Stages document
- Source section: `Этап 2`, paragraph 30
- Type: `ADD`
- Requirement: The master can explicitly reject an AI proposal.
- Acceptance: Deferred; rejection must not overwrite specialist data.
- Dependencies: `REQ-MED-2-AI-001`.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-005 - Preserve corrections as future examples

- Source: Stages document
- Source section: `Этап 2`, paragraph 28
- Type: `ADD`
- Requirement: Specialist corrections to AI drafts are retained as examples for later agents under the approved privacy/provenance policy.
- Acceptance: Deferred; no training/example pipeline is implied in Stage 1.
- Dependencies: `REQ-MED-2-AI-001` through `REQ-MED-2-AI-004`.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-006 - Full health audit for the master

- Source: Stages document
- Source section: `Этап 2`, paragraphs 31 and 37
- Type: `ADD`
- Requirement: Provide a full health-audit version for the master.
- Acceptance: Deferred until the audit structure and approved numbers/thresholds are supplied.
- Dependencies: `REQ-MED-2-INPUT-002`; `REQ-MED-2-INPUT-001`.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-007 - Simple health audit for the client

- Source: Stages document
- Source section: `Этап 2`, paragraph 31
- Type: `ADD`
- Requirement: Provide a simple client-safe health-audit version.
- Acceptance: Deferred until client-safe wording and red-flag policy are approved; Stage 1 does not expose medical results to clients.
- Dependencies: `REQ-MED-2-INPUT-004`; `REQ-MED-2-INPUT-005`; Stage 3 visibility decisions.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-008 - AI protocol and report draft

- Source: Stages document
- Source section: `Этап 2`, paragraph 32
- Type: `ADD`
- Requirement: AI may draft a step-by-step protocol and session report from the approved five-stage algorithm, with master editing.
- Acceptance: Deferred; the draft never becomes a clinical fact or client message without explicit review/send actions.
- Dependencies: Structured protocol/result model; approved algorithm.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-009 - Posture photo agent

- Source: Stages document
- Source section: `Этап 2`, paragraph 33
- Type: `ADD`
- Requirement: A posture agent may analyze session photos using master scores from Visual Test 2 as an anchor.
- Acceptance: Deferred; Stage 1 only stores semantic photos and the master visual test.
- Dependencies: `REQ-MED-1C-PHOTO-001`; Test 2 data.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-010 - Client self-posture through bot

- Source: Stages document
- Source section: `Этап 2`, paragraph 33
- Type: `ADD`
- Requirement: A separate client self-assessment of posture through the bot may be added.
- Acceptance: Deferred; no client-facing posture assessment is added in Stage 1.
- Dependencies: `REQ-MED-2-AI-009`; Stage 3 client surface.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-011 - Pattern analysis of vitals

- Source: Stages document
- Source section: `Этап 2`, paragraph 34
- Type: `ADD`
- Requirement: Analyze before/after vital patterns.
- Acceptance: Deferred; Stage 1 only stores, calculates, and highlights configured values.
- Dependencies: Stage 1C vitals.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-012 - Five-to-ten-visit trends

- Source: Stages document
- Source section: `Этап 2`, paragraph 34
- Type: `ADD`
- Requirement: Analyze vital trends over 5-10 visits using the approved source definitions.
- Acceptance: Deferred; no trend interpretation is inferred in Stage 1.
- Dependencies: `REQ-MED-2-AI-011`; historical vitals.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-013 - Automated index 3 rule

- Source: Stages document; Stage 1 TZ
- Source section: `Этап 2`, paragraph 35; Stage 1 paragraph 78
- Type: `ADD`
- Requirement: A future rule may assign automatic index 3 according to the approved medical table. Stage 1 index values are entered by the master.
- Acceptance: Deferred; no automatic test rule is implemented in Stage 1.
- Dependencies: `REQ-MED-2-INPUT-001`.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-014 - Test 18 AI restriction

- Source: Stages document
- Source section: `Этап 2`, paragraph 35
- Type: `EXCLUDE`
- Requirement: Stage 2 AI must not suggest hard manipulations when Test 18 safety evidence applies.
- Acceptance: Deferred; Stage 1 stores the persistent safety marker but does not run an AI agent.
- Dependencies: `REQ-MED-1B-SPECIAL-004`.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-015 - Total-hypotonia express route

- Source: Stages document
- Source section: `Этап 2`, paragraph 35
- Type: `ADD`
- Requirement: A future express route may be triggered by total hypotonia under the approved rule.
- Acceptance: Deferred; Stage 1 records the underlying test values only.
- Dependencies: T1/T8 records; approved automated rule.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-016 - Global-cause priority

- Source: Stages document
- Source section: `Этап 2`, paragraph 35
- Type: `ADD`
- Requirement: A future agent may prioritize global causes using approved cause semantics and evidence.
- Acceptance: Deferred; Stage 1 aggregates causes but does not rank clinical priority automatically.
- Dependencies: `REQ-MED-1B-ROOT-001`; approved Stage 2 policy.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-017 - Specialized-test suggestion

- Source: Stages document
- Source section: `Этап 2`, paragraph 35
- Type: `ADD`
- Requirement: A future agent may suggest which specialized tests to perform.
- Acceptance: Deferred; Stage 1 does not make automated testing suggestions.
- Dependencies: Test catalog; approved suggestion policy.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-018 - Integrative effectiveness index

- Source: Stages document; Stage 1 TZ
- Source section: `Этап 2`, paragraph 36; `Д.`, paragraph 133
- Type: `EXCLUDE`
- Requirement: A future integrative effectiveness index may combine stored components. Stage 1 explicitly does not calculate one percentage.
- Acceptance: Deferred; Stage 1 stores the component values needed for retrospective calculation.
- Dependencies: `REQ-MED-1B-EFFECT-001`; `REQ-MED-2-INPUT-003`.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-019 - Four health indices

- Source: Stages document
- Source section: `Этап 2`, paragraph 36
- Type: `ADD`
- Requirement: Implement the four health indices from the 03.11 source only after their definitions are approved.
- Acceptance: Deferred; no formula or medical meaning is invented here.
- Dependencies: `REQ-MED-2-INPUT-001`.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-AI-020 - Red-flag client wording and policy

- Source: Stages document
- Source section: `Этап 2`, paragraph 38.6
- Type: `ADD`
- Requirement: Define client-safe red-flag behavior for pressure, saturation, pulse, and Test 18, including recommendation to see a doctor instead of interpretation.
- Acceptance: Deferred until the wording dictionary and behavior are approved; Stage 1 red markers are master-only.
- Dependencies: `REQ-MED-2-INPUT-004`; `REQ-MED-2-INPUT-005`.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### Stage 2 preparation inputs

### REQ-MED-2-INPUT-001 - Approved table of numbers and thresholds

- Source: Stages document
- Source section: `Что нужно подготовить до ТЗ этапа 2`, table row 38.2
- Type: `ADD`
- Requirement: Before Stage 2, prepare the table of numbers and thresholds from the 03.11 document with the team's interpretation and Женя's confirmation.
- Acceptance: The artifact is supplied and owner-confirmed before implementing automated formulas/rules; missing values do not get invented.
- Dependencies: None.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-INPUT-002 - Health Audit structure

- Source: Stages document
- Source section: `Что нужно подготовить до ТЗ этапа 2`, table row 38.3
- Type: `ADD`
- Requirement: Define the composition and structure of Health Audit, including the unanswered 7.3 question.
- Acceptance: Женя supplies the structure before the health-audit implementation begins.
- Dependencies: `REQ-MED-2-AI-006`; `REQ-MED-2-AI-007`.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-INPUT-003 - Integrative formula

- Source: Stages document
- Source section: `Что нужно подготовить до ТЗ этапа 2`, table row 38.4
- Type: `ADD`
- Requirement: Produce a proposal for the integrative effectiveness formula and obtain Женя's decision.
- Acceptance: The approved formula is recorded before the index is implemented; Stage 1 does not choose it.
- Dependencies: `REQ-MED-2-AI-018`.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-INPUT-004 - Client-safe wording dictionary

- Source: Stages document
- Source section: `Что нужно подготовить до ТЗ этапа 2`, table row 38.5
- Type: `ADD`
- Requirement: Define client-safe wording without terms, diagnoses, or percentage forecasts where the source prohibits them.
- Acceptance: The dictionary is jointly approved before client-facing AI/report behavior is implemented.
- Dependencies: `REQ-MED-2-AI-007`; `REQ-MED-2-AI-020`.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

### REQ-MED-2-INPUT-005 - Red-flag behavior

- Source: Stages document
- Source section: `Что нужно подготовить до ТЗ этапа 2`, table row 38.6
- Type: `ADD`
- Requirement: Define behavior for red flags from pressure, saturation, pulse, and Test 18, with a recommendation to consult a doctor instead of interpretation.
- Acceptance: The policy is jointly approved before any client-facing red-flag behavior is released.
- Dependencies: `REQ-MED-2-AI-020`.
- Delivery package: `2`
- Status: `OUT OF STAGE 1`

## Stage 3 backlog - client, communication, payment, exports, and voice

These requirements are preserved from the stages document and are not Stage 1 scope. Five source questions are tracked separately in the Open Questions section.

### REQ-MED-3-CLIENT-001 - Simple client test results

- Source: Stages document
- Source section: `Этап 3`, paragraphs 43-46
- Type: `ADD`
- Requirement: The client can later see approved test results in simple wording.
- Acceptance: Deferred until client-safe wording and visibility decisions are approved; Stage 1 exposes results to the master only.
- Dependencies: `REQ-MED-3-OQ-001`; `REQ-MED-2-INPUT-004`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-CLIENT-002 - Client pain dynamics

- Source: Stages document
- Source section: `Этап 3`, paragraph 45
- Type: `ADD`
- Requirement: The client can later see approved pain dynamics.
- Acceptance: Deferred until client-facing medical wording is approved.
- Dependencies: `REQ-MED-3-CLIENT-001`; structured complaint history.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-CLIENT-003 - Approved recommendations and reports

- Source: Stages document
- Source section: `Этап 3`, paragraph 46
- Type: `ADD`
- Requirement: The client receives only approved recommendations and reports.
- Acceptance: Deferred; Stage 1 recommendations require explicit master send but does not define the client cabinet.
- Dependencies: `REQ-MED-1B-RECOMMEND-001`; `REQ-MED-3-OQ-001`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-CLIENT-004 - Client anamnesis and history

- Source: Stages document
- Source section: `Этап 3`, paragraph 47
- Type: `ADD`
- Requirement: The client may later see the approved client anamnesis/history view.
- Acceptance: Deferred; Stage 1 master-only anamnesis remains hidden from the client.
- Dependencies: `REQ-MED-1A-PROFILE-003`; `REQ-MED-3-OQ-001`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-CLIENT-005 - Selected audio for the client

- Source: Stages document
- Source section: `Этап 3`, paragraph 48
- Type: `ADD`
- Requirement: The client can later see audio records specifically selected by the master.
- Acceptance: Deferred; Stage 1 sends individual audio explicitly and keeps unsent audio invisible.
- Dependencies: `REQ-MED-1C-AUDIO-003`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-CLIENT-006 - Client work stages

- Source: Stages document
- Source section: `Этап 3`, paragraph 49
- Type: `ADD`
- Requirement: The client can later see the stages of work with the expert.
- Acceptance: Deferred; no course/work-stage entity is invented in Stage 1.
- Dependencies: `REQ-MED-3-OQ-002`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### Expert communication

### REQ-MED-3-COMM-001 - Knowledge-base chat

- Source: Stages document
- Source section: `Этап 3`, paragraph 51
- Type: `ADD`
- Requirement: A client-facing platform chat can answer ordinary questions from the approved knowledge base.
- Acceptance: Deferred; the Stage 3 product and knowledge scope must be approved first.
- Dependencies: `REQ-MED-3-OQ-001`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-COMM-002 - Escalation to the expert

- Source: Stages document
- Source section: `Этап 3`, paragraph 52
- Type: `ADD`
- Requirement: Unusual client questions can be escalated to Женя.
- Acceptance: Deferred; escalation authorization and communication surface remain Stage 3 scope.
- Dependencies: `REQ-MED-3-COMM-001`; `REQ-MED-3-OQ-001`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-COMM-003 - Short paid expert consultation

- Source: Stages document
- Source section: `Этап 3`, paragraph 53
- Type: `ADD`
- Requirement: A short paid expert consultation may be offered with a mini-form about the purpose of the request.
- Acceptance: Deferred until prices/types are decided.
- Dependencies: `REQ-MED-3-OQ-003`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-COMM-004 - Long paid expert consultation

- Source: Stages document
- Source section: `Этап 3`, paragraph 53
- Type: `ADD`
- Requirement: A long paid expert consultation may be offered with a mini-form about the purpose of the request.
- Acceptance: Deferred until prices/types are decided.
- Dependencies: `REQ-MED-3-OQ-003`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-COMM-005 - Consultation mini-form

- Source: Stages document
- Source section: `Этап 3`, paragraph 53
- Type: `ADD`
- Requirement: A future consultation request includes a short form describing the goal of the contact.
- Acceptance: Deferred; form fields and content are not invented here.
- Dependencies: `REQ-MED-3-OQ-003`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### Bot messages and satisfaction

### REQ-MED-3-BOT-001 - +24 hour message

- Source: Stages document
- Source section: `Этап 3`, paragraph 55
- Type: `ADD`
- Requirement: A future bot flow may send a message 24 hours after a session.
- Acceptance: Deferred; Stage 1 does not add client-facing auto-messages.
- Dependencies: `REQ-MED-3-OQ-004`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-BOT-002 - Next-day wellbeing message

- Source: Stages document
- Source section: `Этап 3`, paragraph 56
- Type: `ADD`
- Requirement: A future bot flow may ask about wellbeing and a possible small exacerbation on the next day.
- Acceptance: Deferred; copy and red-flag policy are not invented here.
- Dependencies: `REQ-MED-3-OQ-004`; `REQ-MED-2-INPUT-005`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-BOT-003 - Course satisfaction and <8 follow-up

- Source: Stages document
- Source section: `Этап 3`, paragraph 57
- Type: `ADD`
- Requirement: At course end, collect satisfaction tagged `Сервис`, `Результат`, and `Общение`; if a score is below 8, ask `чего не хватило`.
- Acceptance: Deferred until the course model and approved copy exist.
- Dependencies: `REQ-MED-3-OQ-002`; `REQ-MED-3-OQ-004`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### Payment and exports

### REQ-MED-3-PAY-001 - LAVA

- Source: Stages document
- Source section: `Этап 3`, paragraph 59
- Type: `EXCLUDE`
- Requirement: LAVA payment is future Stage 3 scope.
- Acceptance: Deferred; no LAVA implementation is included in Stage 1.
- Dependencies: Separate payments scope and provider decision.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-PAY-002 - Crypto payments

- Source: Stages document
- Source section: `Этап 3`, paragraph 59
- Type: `EXCLUDE`
- Requirement: Crypto payment is future Stage 3 scope.
- Acceptance: Deferred; no crypto implementation is included in Stage 1.
- Dependencies: Separate payments scope and provider decision.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-EXPORT-001 - Word export

- Source: Stages document; Stage 1 TZ
- Source section: `Этап 3`, paragraph 61; `И.`, paragraph 234
- Type: `EXCLUDE`
- Requirement: Word export is deferred to Stage 3.
- Acceptance: No Word export is implemented in Stage 1.
- Dependencies: `REQ-MED-1C-PDF-002`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-EXPORT-002 - Excel questionnaire export

- Source: Stages document; Stage 1 TZ
- Source section: `Этап 3`, paragraph 61; catalog/table import note
- Type: `EXCLUDE`
- Requirement: Excel questionnaire export is deferred to Stage 3. Catalog CSV/XLSX import/export is only `WORTHWHILE` in Stage 1B and is separate.
- Acceptance: No Excel questionnaire export is implemented in Stage 1.
- Dependencies: `REQ-MED-1C-PDF-002`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-EXPORT-003 - Medical email sending

- Source: Stages document; Stage 1 TZ
- Source section: `Этап 3`, paragraph 61; `И.`, paragraph 234
- Type: `EXCLUDE`
- Requirement: Sending medical questionnaire/card material by email is deferred to Stage 3.
- Acceptance: No email delivery is added to Stage 1; the separate source question about consent remains open.
- Dependencies: `REQ-MED-3-OQ-005`; `REQ-MED-1C-PDF-002`.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-EXPORT-004 - Import old PDF questionnaires

- Source: Stages document
- Source section: `Этап 3`, paragraph 62
- Type: `EXCLUDE`
- Requirement: Importing old questionnaires from PDF is deferred to Stage 3.
- Acceptance: No old-PDF import is implemented in Stage 1.
- Dependencies: Compatibility requirements remain non-destructive.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-VOICE-001 - Custom voice input only if native dictation is insufficient

- Source: Stages document
- Source section: `Этап 3`, paragraph 63
- Type: `EXCLUDE`
- Requirement: A custom voice implementation is considered only after practical testing shows that native phone/computer dictation is insufficient.
- Acceptance: No custom voice system is implemented in Stage 1.
- Dependencies: Practical Stage 1 observation.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

### REQ-MED-3-CLIENT-007 - Client-facing medical result changes belong to Stage 3

- Source: Stage 1 TZ; stages document
- Source section: Stage 1 exclusions; `Этап 3`, paragraphs 41-49
- Type: `EXCLUDE`
- Requirement: Client-facing changes to medical results, dynamics, history, recommendations, and reports are Stage 3 scope, except for Stage 1's explicit master-only send action and protected delivery state.
- Acceptance: Stage 1 does not expose clinical session results, Test 18 markers, or vitals red markers to the client.
- Dependencies: `REQ-MED-1C-PDF-001`; Stage 3 client requirements.
- Delivery package: `3`
- Status: `OUT OF STAGE 1`

## Stage 4 backlog - scale

Stage 4 preserves the scaling direction. The Stage 1 seams are already explicit in `REQ-MED-1A-CORE-004` (master on each record), `REQ-MED-1A-CORE-005` (editable/shared dictionaries), and the Stage 1B catalog/tag moderation records.

### REQ-MED-4-SCALE-001 - Students and multiple masters

- Source: Stages document
- Source section: `Этап 4`, paragraphs 71-75
- Type: `ADD`
- Requirement: Other specialists and students can work in the same system under controlled master ownership.
- Acceptance: Deferred; Stage 1 only preserves the architectural seam and does not expose a multi-master product UI.
- Dependencies: `REQ-MED-1A-CORE-004`.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-002 - Roles

- Source: Stages document
- Source section: `Этап 4`, paragraph 75
- Type: `ADD`
- Requirement: Future multi-master operation has explicit roles.
- Acceptance: Deferred; no role matrix is invented in Stage 1.
- Dependencies: `REQ-MED-4-SCALE-001`.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-003 - Test and full cabinet versions

- Source: Stages document
- Source section: `Этап 4`, paragraph 75
- Type: `ADD`
- Requirement: Future students/masters may have test and full versions of the cabinet.
- Acceptance: Deferred; Stage 1 does not expose another master cabinet.
- Dependencies: `REQ-MED-4-SCALE-001`; `REQ-MED-4-SCALE-002`.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-004 - Subscription or packages

- Source: Stages document
- Source section: `Этап 4`, paragraph 75
- Type: `ADD`
- Requirement: Future scale may use subscriptions or packages.
- Acceptance: Deferred; no course/package entity is invented in Stage 1.
- Dependencies: Stage 3 course/payment decisions.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-005 - Shared test and tag catalogs

- Source: Stages document
- Source section: `Этап 4`, paragraph 76
- Type: `REUSE`
- Requirement: Future masters use shared test and tag catalogs where authorized.
- Acceptance: Deferred; Stage 1 catalog records remain organization-safe and editable through the defined controls.
- Dependencies: `REQ-MED-1B-CATALOG-001`; `REQ-MED-1B-TAG-001`.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-006 - Student tag moderation

- Source: Stages document
- Source section: `Этап 4`, paragraph 77
- Type: `REUSE`
- Requirement: New tags proposed by students go through Женя's moderation; the proposal/moderation mechanism is prepared in Stage 1.
- Acceptance: Deferred for student UI; Stage 1 implements only the common lifecycle seam.
- Dependencies: `REQ-MED-1B-TAG-008`; `REQ-MED-1B-TAG-009`.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-007 - Practitioner analytics

- Source: Stages document
- Source section: `Этап 4`, paragraph 78
- Type: `ADD`
- Requirement: Future analytics compare practitioner effectiveness.
- Acceptance: Deferred; Stage 1 only supplies structured data and the short current-console instruction.
- Dependencies: `REQ-MED-1A-CALL-008`; Stage 1B counters.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-008 - Knowledge gaps

- Source: Stages document
- Source section: `Этап 4`, paragraph 78
- Type: `ADD`
- Requirement: Future analytics identify knowledge gaps across practitioners.
- Acceptance: Deferred; no clinical interpretation is invented here.
- Dependencies: `REQ-MED-4-SCALE-007`.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-009 - Trends over the full base

- Source: Stages document
- Source section: `Этап 4`, paragraph 78
- Type: `ADD`
- Requirement: Future analytics identify trends across the full data base.
- Acceptance: Deferred; Stage 1 does not build the practitioner analytics console.
- Dependencies: `REQ-MED-4-SCALE-007`; structured history.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-010 - Corporate clients

- Source: Stages document
- Source section: `Этап 4`, paragraph 79
- Type: `ADD`
- Requirement: Corporate/wellness-center clients may later connect to the system.
- Acceptance: Deferred; no corporate product model is invented in Stage 1.
- Dependencies: Multi-master and tenant/product decisions.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-011 - Franchise

- Source: Stages document
- Source section: `Этап 4`, paragraph 79
- Type: `ADD`
- Requirement: Franchise operation may later connect to the system.
- Acceptance: Deferred; no franchise policy is invented in Stage 1.
- Dependencies: `REQ-MED-4-SCALE-010`.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-012 - Technique video library

- Source: Stages document
- Source section: `Этап 4`, paragraph 80
- Type: `ADD`
- Requirement: Future work may include a video library of techniques.
- Acceptance: Deferred; Stage 1 does not add a technique library.
- Dependencies: None.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-013 - Suggested technique sequence

- Source: Stages document
- Source section: `Этап 4`, paragraph 81
- Type: `ADD`
- Requirement: The future system may suggest a sequence of techniques.
- Acceptance: Deferred; no sequence is automatically suggested in Stage 1.
- Dependencies: `REQ-MED-4-SCALE-012`; Stage 2 protocol boundaries.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-014 - Record performed techniques

- Source: Stages document
- Source section: `Этап 4`, paragraph 82
- Type: `ADD`
- Requirement: The future system records which techniques a master performed.
- Acceptance: Deferred; Stage 1 stores protocol steps, not a technique execution library.
- Dependencies: `REQ-MED-1A-PROTOCOL-001`.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-015 - Technique error assistance

- Source: Stages document
- Source section: `Этап 4`, paragraph 83
- Type: `ADD`
- Requirement: The future system may help identify technique errors.
- Acceptance: Deferred; no clinical or technique error inference is implemented in Stage 1.
- Dependencies: `REQ-MED-4-SCALE-014`; approved methodology.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-016 - App Store application

- Source: Stages document
- Source section: `Этап 4`, paragraph 84
- Type: `ADD`
- Requirement: A native App Store application may be built later.
- Acceptance: Deferred; Stage 1 does not create a native app.
- Dependencies: Existing Telegram/Mini App continuity.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-017 - Google Play application

- Source: Stages document
- Source section: `Этап 4`, paragraph 84
- Type: `ADD`
- Requirement: A native Google Play application may be built later.
- Acceptance: Deferred; Stage 1 does not create a native app.
- Dependencies: Existing Telegram/Mini App continuity.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-018 - Telegram and Mini App continuity

- Source: Stages document
- Source section: `Этап 4`, paragraph 84
- Type: `REUSE`
- Requirement: Telegram and the Mini App remain supported while later native applications are considered; the Mini App is kept portable.
- Acceptance: Deferred scale concern; Stage 1 does not replace the current channel product.
- Dependencies: Existing Telegram/Mini App architecture.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-019 - Smart-ring integration

- Source: Stages document
- Source section: `Этап 4`, paragraph 85
- Type: `ADD`
- Requirement: Future integration may read night pulse from a smart ring.
- Acceptance: Deferred; no device integration is added in Stage 1.
- Dependencies: Provider/device contract.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-020 - Measurement-device integrations

- Source: Stages document
- Source section: `Этап 4`, paragraph 85
- Type: `ADD`
- Requirement: Future integrations may connect measurement devices.
- Acceptance: Deferred; Stage 1 uses manual structured input and device photos where specified.
- Dependencies: Provider/device contracts; `REQ-MED-1C-VITALS-001`.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

### REQ-MED-4-SCALE-021 - Other methodologies are a separate future project

- Source: Stages document
- Source section: `Этап 4`, paragraph 86
- Type: `EXCLUDE`
- Requirement: Adapting the system to other methodologies is a separate future project after the primary system is improved.
- Acceptance: No other methodology is added to the Stage 1 medical catalog.
- Dependencies: None.
- Delivery package: `4`
- Status: `OUT OF STAGE 1`

## Stage 3 source open questions

These source questions are preserved as separate non-blocking records. They do not block Stage 1, but they must be answered before the dependent Stage 3 behavior is accepted.

### REQ-MED-3-OQ-001 - What exactly the client sees

- Source: Stages document
- Source section: `Этап 3`, `Открытые вопросы`, paragraph 65
- Type: `ADD`
- Requirement: Decide the exact client-visible scope for medical results, history, recommendations, reports, and related content.
- Acceptance: Owner-approved client-safe visibility is recorded before Stage 3 client medical surfaces are implemented.
- Dependencies: `REQ-MED-3-CLIENT-001` through `REQ-MED-3-CLIENT-007`; `REQ-MED-2-INPUT-004`.
- Delivery package: `3 / Open Question`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-3-OQ-002 - Course model

- Source: Stages document
- Source section: `Этап 3`, paragraph 58; `Открытые вопросы`, paragraph 66
- Type: `ADD`
- Requirement: Decide whether a course is a separate entity or only a `курс завершён` marker. Do not invent a course entity.
- Acceptance: Owner decision is recorded before course satisfaction, work stages, subscription/package, or course messaging is implemented.
- Dependencies: `REQ-MED-3-BOT-003`; `REQ-MED-3-CLIENT-006`; `REQ-MED-4-SCALE-004`.
- Delivery package: `3 / Open Question`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-3-OQ-003 - Prices and consultation types

- Source: Stages document
- Source section: `Открытые вопросы`, paragraph 67
- Type: `ADD`
- Requirement: Decide consultation prices and the exact types of short and long consultations.
- Acceptance: Owner-approved service/pricing definitions exist before paid consultation UI or payment flow is implemented.
- Dependencies: `REQ-MED-3-COMM-003` through `REQ-MED-3-COMM-005`.
- Delivery package: `3 / Open Question`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-3-OQ-004 - Auto-message copy

- Source: Stages document
- Source section: `Открытые вопросы`, paragraph 68
- Type: `ADD`
- Requirement: Approve the text of future automated bot messages.
- Acceptance: Approved copy exists before Stage 3 auto-message delivery is enabled; no medical wording is invented in Stage 1.
- Dependencies: `REQ-MED-3-BOT-001` through `REQ-MED-3-BOT-003`.
- Delivery package: `3 / Open Question`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-3-OQ-005 - Consent for medical email

- Source: Stages document
- Source section: `Открытые вопросы`, paragraph 69
- Type: `ADD`
- Requirement: Decide whether and how a client consents to medical-data email sending.
- Acceptance: Legal/owner decision is recorded before any Stage 3 medical email behavior; Stage 1 has no email send.
- Dependencies: `REQ-MED-3-EXPORT-003`.
- Delivery package: `3 / Open Question`
- Status: `OPEN QUESTION - NON-BLOCKING`

## Explicit OUT OF STAGE 1 register

The following items must not be implemented as part of Stage 1. The corresponding detailed REQs above are the future source of truth.

| Excluded from Stage 1 | Canonical future REQ(s) |
|---|---|
| AI agent suggesting causes, root cause, or protocol | `REQ-MED-2-AI-001`, `REQ-MED-2-AI-008` |
| Automated test rules | `REQ-MED-2-AI-013`, `REQ-MED-2-AI-015`, `REQ-MED-2-AI-016`, `REQ-MED-2-AI-017` |
| Health audit and health indices | `REQ-MED-2-AI-006`, `REQ-MED-2-AI-007`, `REQ-MED-2-AI-019` |
| Integrative effectiveness index | `REQ-MED-2-AI-018` |
| AI analysis of vitals | `REQ-MED-2-AI-011`, `REQ-MED-2-AI-012` |
| Client-facing medical result changes belonging to Stage 3 | `REQ-MED-3-CLIENT-001` through `REQ-MED-3-CLIENT-007` |
| Paid expert consultation | `REQ-MED-3-COMM-003` through `REQ-MED-3-COMM-005` |
| Stage 3 auto-messages | `REQ-MED-3-BOT-001` through `REQ-MED-3-BOT-003` |
| Course model | `REQ-MED-3-OQ-002` |
| LAVA | `REQ-MED-3-PAY-001` |
| Crypto | `REQ-MED-3-PAY-002` |
| Custom voice input | `REQ-MED-3-VOICE-001` |
| Word export | `REQ-MED-3-EXPORT-001` |
| Excel questionnaire export | `REQ-MED-3-EXPORT-002` |
| Medical email sending | `REQ-MED-3-EXPORT-003`, `REQ-MED-3-OQ-005` |
| Old PDF import | `REQ-MED-3-EXPORT-004` |
| Student cabinets and multi-master product UI | `REQ-MED-4-SCALE-001` through `REQ-MED-4-SCALE-003` |
| Corporate/franchise | `REQ-MED-4-SCALE-010`, `REQ-MED-4-SCALE-011` |
| Native mobile applications | `REQ-MED-4-SCALE-016`, `REQ-MED-4-SCALE-017` |

## Official Stage 1 delivery packages and acceptance matrices

The following are the official package boundaries. They are acceptance checklists, not evidence that any check has run. Package status in this documentation task is `NOT RUN`; later implementation tasks must attach named evidence to the relevant REQs.

Each package is independently checked by the agent on staging with PostgreSQL and safe test data. Real clients and real medical data are not required for technical package acceptance. Personal or medical information from real clients must not appear in public reports or pull requests. The separate final Stage 1 usability confirmation below does not replace this agent-led QA.

### Stage 1A acceptance matrix

| Acceptance proof | REQ coverage | Status |
|---|---|---|
| Exact block order 0 plus 1-10 and exact labels | `REQ-MED-1A-SESSION-001` | NOT RUN |
| Autosave on change; close/reopen; draft/completed; complete; edit completed; `изменено` + date | `REQ-MED-1A-SESSION-009` through `REQ-MED-1A-SESSION-014` | NOT RUN |
| General pain scale and old objective-change field absent from active form | `REQ-MED-1A-SESSION-002`, `REQ-MED-1A-SESSION-003` | NOT RUN |
| Disabled AI controls, Stage 2 tooltip, no mutation | `REQ-MED-1A-SESSION-004` through `REQ-MED-1A-SESSION-007` | NOT RUN |
| Long blocks collapse without data loss | `REQ-MED-1A-SESSION-008` | NOT RUN |
| Three time-of-day rows: `поясница` morning 8 / day 3 / evening 7, after values, next-session carryover | `REQ-MED-1A-COMPLAINT-005` through `REQ-MED-1A-COMPLAINT-023` | NOT RUN |
| Questionnaire seeds first-session complaints and `из анкеты, уточнить`; free text maps to `Особые жалобы` | `REQ-MED-1A-COMPLAINT-016` through `REQ-MED-1A-COMPLAINT-018`; `REQ-MED-1A-QUESTIONNAIRE-005` | NOT RUN |
| Removed/returned complaint and history | `REQ-MED-1A-COMPLAINT-021` through `REQ-MED-1A-COMPLAINT-023` | NOT RUN |
| Operations and traumas with 0, 1, many rows, including no year | `REQ-MED-1A-QUESTIONNAIRE-008` through `REQ-MED-1A-QUESTIONNAIRE-010` | NOT RUN |
| Client anamnesis absent; master anamnesis visible with exact placeholder; old values retained | `REQ-MED-1A-QUESTIONNAIRE-011`, `REQ-MED-1A-PROFILE-002`, `REQ-MED-1A-PROFILE-003`, `REQ-MED-COMPAT-009` | NOT RUN |
| Timezone journey: saved master timezone/hours, correct availability, client time + label, master time, one conversion | `REQ-MED-1A-CALL-001` through `REQ-MED-1A-CALL-006` | NOT RUN |
| `Вернуть ИИ-помощника` sends the client system message | `REQ-MED-1A-CALL-007` | NOT RUN |
| Analytics is not redesigned; one-page human instruction exists | `REQ-MED-1A-CALL-008` | NOT RUN |
| Desktop/tablet/mobile where applicable and first-time usability | `REQ-MED-1A-CORE-007`, `REQ-MED-1A-CORE-008`, `REQ-MED-1A-CORE-010` | NOT RUN |
| Compatibility behavior is proven on staging with PostgreSQL | `REQ-MED-COMPAT-001` through `REQ-MED-COMPAT-015` | NOT RUN |
| Exact final candidate SHA is identified in staging evidence | Repository release gate; package evidence | NOT RUN |

### Stage 1B acceptance matrix

| Acceptance proof | REQ coverage | Status |
|---|---|---|
| All T1-T12 are exercised with their type-specific controls | `REQ-MED-1B-TYPE-001` through `REQ-MED-1B-TYPE-012` | NOT RUN |
| All 31 tests are configured and individually readable | `REQ-MED-1B-TEST-001` through `REQ-MED-1B-TEST-031` | NOT RUN |
| Add a custom test without code; choose one of 12 types | `REQ-MED-1B-CATALOG-001`, `REQ-MED-1B-CATALOG-002` | NOT RUN |
| Exact default Main set `1, 2, 3, 4, 5, 6, 7, 9, 25, 31.1`; remove Main; add Specialized; search | `REQ-MED-1B-COMMON-002` through `REQ-MED-1B-COMMON-005` | NOT RUN |
| Before/after, 0/1/2/3, 0 normal and type-specific fields hidden/open | `REQ-MED-1B-COMMON-006` through `REQ-MED-1B-COMMON-009` | NOT RUN |
| Primary/accompanying causes, `Также`, global cause search/create | `REQ-MED-1B-COMMON-010` through `REQ-MED-1B-COMMON-013` | NOT RUN |
| Previous after grey, automatic recheck, new before empty | `REQ-MED-1B-COMMON-014` through `REQ-MED-1B-COMMON-017` | NOT RUN |
| Counters manually cross-check before, after, resolved, new, session-only | `REQ-MED-1B-COUNTER-001` through `REQ-MED-1B-COUNTER-005` | NOT RUN |
| Test 1 standing/lying; Test 8 formula/highlight/correction; Test 10 warning | `REQ-MED-1B-SPECIAL-001` through `REQ-MED-1B-SPECIAL-003` | NOT RUN |
| Test 18 persistent red marker, exact warning, clear with date/reason, editable text | `REQ-MED-1B-SPECIAL-004` | NOT RUN |
| Test 19 mechanical/informational type | `REQ-MED-1B-SPECIAL-005` | NOT RUN |
| Create/search cause, `Применить к`, shared reuse, moderation lifecycle, merge preserves history | `REQ-MED-1B-TAG-001` through `REQ-MED-1B-TAG-013` | NOT RUN |
| Root cause main selection and optional `на это влияет` relation | `REQ-MED-1B-ROOT-001` through `REQ-MED-1B-ROOT-003` | NOT RUN |
| Deterministic result, five hints, second `Собрать` overwrite confirmation, no AI | `REQ-MED-1B-RESULT-001` through `REQ-MED-1B-RESULT-003` | NOT RUN |
| Effectiveness components, negative worsening, no integrative percentage | `REQ-MED-1B-EFFECT-001`, `REQ-MED-1B-EFFECT-002` | NOT RUN |
| Explicit recommendation send, Telegram status/date, client card/PDF access | `REQ-MED-1B-RECOMMEND-001`, `REQ-MED-1B-RECOMMEND-002` | NOT RUN |
| Tablet usability and first-time usability | `REQ-MED-1A-CORE-007`, `REQ-MED-1A-CORE-008`, `REQ-MED-1A-CORE-010` | NOT RUN |

### Stage 1C acceptance matrix

| Acceptance proof | REQ coverage | Status |
|---|---|---|
| Before/after vitals, optional fields, explicit after enable | `REQ-MED-1C-VITALS-001`, `REQ-MED-1C-VITALS-002` | NOT RUN |
| Tablet numeric keyboard and PI range/precision | `REQ-MED-1C-VITALS-003` | NOT RUN |
| Configurable thresholds, green/yellow/red, `138/88 -> PD 50 / MAP 105`, `SpO2 89 -> red` | `REQ-MED-1C-VITALS-001`, `REQ-MED-1C-VITALS-004`, `REQ-MED-1C-VITALS-006` | NOT RUN |
| Red session marker is master-only; history by date/before/after | `REQ-MED-1C-VITALS-005`, `REQ-MED-1C-VITALS-006` | NOT RUN |
| Anthropometry fields, BMI 22.9 for 175/70, previous height, history | `REQ-MED-1C-ANTHRO-001`, `REQ-MED-1C-ANTHRO-002` | NOT RUN |
| All six photo areas, multiple photos, tablet camera/file, preview/delete | `REQ-MED-1C-PHOTO-001`, `REQ-MED-1C-PHOTO-002` | NOT RUN |
| Photo tab grouping/labels/plane filter and master-only access | `REQ-MED-1C-PHOTO-003` | NOT RUN |
| Original download matches stored original; archive works | `REQ-MED-1C-PHOTO-004` | NOT RUN |
| File category split and questionnaire-upload destination | `REQ-MED-1C-FILES-001` | NOT RUN |
| File/video preview/download/metadata and policy-visible limits | `REQ-MED-1C-FILES-002`, `REQ-MED-1C-FILES-003`, `REQ-MED-1C-FILES-004` | NOT RUN |
| Audio Telegram send, sent date/status, unsent invisibility, large-file fallback | `REQ-MED-1C-AUDIO-001` through `REQ-MED-1C-AUDIO-004` | NOT RUN |
| Avatar default/fallback/upload/replace on all required surfaces | `REQ-MED-1C-AVATAR-001` | NOT RUN |
| PDF exact include/exclude list | `REQ-MED-1C-PDF-001`, `REQ-MED-1C-PDF-002` | NOT RUN |
| Final compatibility and PostgreSQL evidence | `REQ-MED-COMPAT-001` through `REQ-MED-COMPAT-015` | NOT RUN |

### Final Stage 1 acceptance

| Acceptance proof | REQ coverage | Status |
|---|---|---|
| After independent 1A, 1B, and 1C staging QA, Женя completes 2-3 real sessions without phone notes | `REQ-MED-1A-CORE-009` | NOT RUN |

## Source-to-REQ traceability pass

The source block notation below uses the complete paragraph/table extraction of the three supplied DOCX. A range is inclusive. Every normative paragraph, list item, and table row in the range is covered by at least one listed REQ. Title/date/author metadata and section labels are non-normative and are explicitly excluded from the normative count.

### Stage 1 TZ and stages source

| Source blocks | Mapped REQs |
|---|---|
| Stage 1 TZ P3-P20 | `REQ-MED-1A-CORE-001` through `REQ-MED-1A-CORE-010`; Stage 2-4 and exclusion REQs |
| Stage 1 TZ P21-P22, T23.2-T23.12, and P24-P29 | `REQ-MED-1A-SESSION-001` through `REQ-MED-1A-SESSION-014`; `REQ-MED-1C-VITALS-002` |
| Stage 1 TZ P30-P42 and T33.2-T33.6 | `REQ-MED-1A-COMPLAINT-001` through `REQ-MED-1A-COMPLAINT-023` |
| Stage 1 TZ P43-P79 | Stage 1B catalog link, common behavior, special rules, counters, and Stage 2 exclusions in the catalog file |
| Stage 1 TZ P80-P102 | Stage 1B tag REQs in the catalog file |
| Stage 1 TZ P103-P142 | Stage 1B root/result/effectiveness/recommendation REQs in the catalog file |
| Stage 1 TZ P143-P166 and T147.2-T147.9 | `REQ-MED-1A-PROFILE-004`; `REQ-MED-1C-VITALS-001` through `REQ-MED-1C-VITALS-006`; `REQ-MED-1C-ANTHRO-001`, `REQ-MED-1C-ANTHRO-002` |
| Stage 1 TZ P167-P182 | `REQ-MED-1C-PHOTO-001` through `REQ-MED-1C-PHOTO-005` |
| Stage 1 TZ P183-P207 | `REQ-MED-1A-QUESTIONNAIRE-001` through `REQ-MED-1A-QUESTIONNAIRE-014`; `REQ-MED-1A-PROFILE-001` through `REQ-MED-1A-PROFILE-006`; `REQ-MED-COMPAT-009` |
| Stage 1 TZ P208-P239 | `REQ-MED-1C-FILES-001` through `REQ-MED-1C-FILES-004`; `REQ-MED-1C-AUDIO-001` through `REQ-MED-1C-AUDIO-004`; `REQ-MED-1C-AVATAR-001`; `REQ-MED-1C-PDF-001`, `REQ-MED-1C-PDF-002` |
| Stage 1 TZ P240-P241, T242.2-T242.5, and P243-P251 | `REQ-MED-1A-CALL-001` through `REQ-MED-1A-CALL-008`; package acceptance matrices; `REQ-MED-1C-FILES-003`; the explicit non-product source-directive register below |
| Stages P3-P9 | `REQ-MED-1A-CORE-001`, `REQ-MED-1A-CORE-002`, `REQ-MED-1A-CORE-009`, `REQ-MED-1A-CORE-010`; Stage 2-4 backlog boundaries |
| Stages P10-P26 and T26.2-T26.5 | Stage 1 package REQs; `REQ-MED-1A-CORE-009`; `REQ-MED-2-INPUT-001`; pulse-wave OQs in catalog |
| Stages P27-P39 and T38.2-T38.6 | `REQ-MED-2-AI-001` through `REQ-MED-2-INPUT-005` |
| Stages P40-P70 | Stage 3 REQs and `REQ-MED-3-OQ-001` through `REQ-MED-3-OQ-005` |
| Stages P71-P90 | Stage 4 REQs and Stage 1 architectural-seam references |

### Non-product source directives explicitly accounted for

These source blocks are not silently dropped. They are delivery/provenance instructions rather than product behavior, so they are recorded here instead of being fabricated as medical REQs:

- Stage 1 TZ P244 asks for a time/cost estimate. This documentation-only task does not invent an estimate; future implementation planning owns it.
- Stage 1 TZ P245-P248 defines the handoff order. It is represented by the official 1A/1B/1C package matrices above.
- Stage 1 TZ P249 asks questions to be placed as comments in the source documents. The DOCX sources remain primary; this repo ledger represents each decision point with stable OQ IDs and does not mutate the private source files.
- Stage 1 TZ P250 asks for audio/video/MRI limits. `REQ-MED-1C-FILES-003` preserves the request and requires the final values to be owner/admin-facing; no value is invented.
- Stage 1 TZ P251 asks for a test environment. The package matrices require later staging evidence at the exact candidate SHA; this documentation task does not deploy staging.
- Stages P23-P24 describe existing behavior and source-document provenance. They are evidence for `REQ-MED-1A-CORE-002` and the authority section, not a claim of implementation.
- Stages P70 describes current non-medical content entry for services and gift certificates. It remains outside this medical Stage 1 ledger and is not turned into a new medical requirement.

### Test catalog source

The test catalog traceability matrix, including each generic form row, each of the 31 test rows, and all 13 catalog-question rows, is in [`2026-11-14-medical-test-catalog.md`](2026-11-14-medical-test-catalog.md). Its final audit records the exact source block inventory and unmapped count.

## Source conflicts and handling

| Source difference | Canonical handling |
|---|---|
| Generic T11 description says `20 органов - 20 нарушений`, while the test 28 row says `34 органа и 10 причин`. | Preserve both source statements in traceability. The test-specific row remains `34 органа` for test 28; the generic T11 sentence is not used to overwrite test 28. No clinical interpretation is invented. |
| Test 1 source context mentions a shorter standing cause list, while the catalog question resolves both standing and lying to the 21-tag list. | Preserve the question and answer as source history; the canonical test rule uses the resolved 21-tag shared set, subject to the editable catalog. |
| The source pulse-wave table contains a placeholder `Тип A / Тип B / Тип C`, while the catalog question says the final list is still needed and mentions A/C. | Use a configurable catalog with a placeholder until Женя supplies the authoritative names. This is non-blocking and does not invent the final list. |
| The file source gives an approximate 30 seconds / 50 MB video guideline and asks the implementer to name the exact limit, while the current task forbids hidden business policy invention. | Preserve the guidance, require an owner/admin-facing policy/configuration, and do not choose a hidden limit in this ledger. |
| The stages document calls the session card 10 blocks, while the detailed Stage 1 table has an unnumbered pre-session vitals block plus numbered blocks 1-10. | Canonical order uses the unnumbered `0` vitals block plus the exact numbered 1-10 blocks. |

## Documentation-only guardrails

- Application code changed: `NO`.
- Migrations changed or run: `NO`.
- Staging deployed: `NO`.
- Existing product bugs fixed: `NO`.
- UX redesign performed: `NO`; this file records the usability acceptance contract only.
- Heavy CI or full product audit required for this documentation candidate: `NO`.
- Future implementation must not mark a REQ `IMPLEMENTED` or `VERIFIED` from this documentation alone.
- Owner acceptance for this documentation task: `NOT RUN`.

## Traceability audit result

- Normative source blocks inventoried: `440` total, consisting of `72` catalog blocks, `273` detailed Stage 1 TZ blocks, and `95` stages/backlog blocks.
- Source requirements mapped to one or more REQs or explicitly classified as non-product delivery/provenance input: `440 / 440`.
- Silently unmapped normative source blocks: `0`.
