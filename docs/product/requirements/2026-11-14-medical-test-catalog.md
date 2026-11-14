# Medical Test Catalog Requirements, 2026-11-14

This file is the canonical Stage 1B test-engine ledger linked from [`2026-11-14-medical-stage1.md`](2026-11-14-medical-stage1.md). It is documentation only. The source catalog remains primary evidence; this file is its stable, traceable engineering reflection.

## Source and catalog rules

Primary source: `Каталог тестов как вносится результат 14.11.26.docx`. The detailed Stage 1 TZ references this catalog for the complete test behavior. The catalog says that each test's cause list comes from Женя's 03.11 source and is not repeated in the 14.11 catalog. This ledger therefore records every source-provided test row, count, zone, position, subtest, and cause-list reference without fabricating a missing clinical tag list.

All Stage 1B REQs start as `TO IMPLEMENT` unless explicitly marked `EXISTING - NEEDS VERIFICATION`, `WORTHWHILE`, or `OPEN QUESTION - NON-BLOCKING`. No catalog REQ is `IMPLEMENTED`, `VERIFIED`, or `OWNER ACCEPTED` in this documentation task.

## Stage 1B ledger: catalog and common session behavior

### REQ-MED-1B-CATALOG-001 - Configurable test catalog fields

- Source: Test catalog; Stage 1 TZ
- Source section: catalog `Как читать каталог`, paragraphs 4-14; Stage 1 TZ `В. Тесты`, paragraphs 43-54
- Type: `ADD`
- Requirement: Each test catalog record configures number, title, set (`Основные` or `Специализированные`), subtests, form type T1-T12, rows, zones, sides, positions, allowed cause tags, index 0-3 enabled/disabled, violation rule, tooltip/abbreviation help, and active/inactive state.
- Acceptance: An authorized owner/admin can inspect and change every listed field through CRM; session behavior reads the configuration rather than hardcoded test-specific form code.
- Dependencies: `REQ-MED-1A-CORE-005`; `REQ-MED-1B-TYPE-001` through `REQ-MED-1B-TYPE-012`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-CATALOG-002 - Add a new test without code

- Source: Test catalog; Stage 1 TZ
- Source section: catalog paragraph 12; Stage 1 TZ paragraph 55
- Type: `ADD`
- Requirement: Женя can add a test to any set and choose one of the 12 generic input types without a code change.
- Acceptance: A newly cataloged test can be added to a session and filled using the selected type; no developer or deployment step is required for the catalog operation.
- Dependencies: `REQ-MED-1B-CATALOG-001`; generic type REQs.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-CATALOG-003 - Catalog table import/export is worthwhile

- Source: Stage 1 TZ; user task instruction
- Source section: Stage 1 TZ paragraph 55
- Type: `WORTHWHILE`
- Requirement: CSV/XLSX catalog import/export may be provided. It is desirable, not an acceptance blocker for Stage 1B.
- Acceptance: If implemented, it preserves the same validated catalog model; if not, the package report explicitly records `WORTHWHILE` as not blocking acceptance.
- Dependencies: `REQ-MED-1B-CATALOG-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-001 - Count only tests actually added to the session

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rule paragraph 6; Stage 1 TZ paragraph 77
- Type: `CHANGE`
- Requirement: Session counters and effectiveness use only tests actually added to that session, not the complete catalog.
- Acceptance: Removing a default test excludes it from session counters; an inactive or unselected test contributes no violation.
- Dependencies: `REQ-MED-1B-CATALOG-001`; counter REQs.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-002 - Exact default Main set

- Source: Test catalog; Stage 1 TZ
- Source section: catalog test table; Stage 1 TZ paragraph 57
- Type: `ADD`
- Requirement: A new session defaults to Main set `1, 2, 3, 4, 5, 6, 7, 9, 25, 31.1` in that order.
- Acceptance: A new session contains exactly those entries by default, with `31.1` represented as the energy-potential subtest; the configured set remains editable later.
- Dependencies: `REQ-MED-1B-CATALOG-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-003 - Remove any Main test

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rule paragraph 6; Stage 1 TZ paragraph 57
- Type: `CHANGE`
- Requirement: The master can remove any default Main test from the current session.
- Acceptance: The removed test is absent from the session and from all session counters, while the shared catalog record remains available.
- Dependencies: `REQ-MED-1B-COMMON-001`; `REQ-MED-1B-COMMON-002`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-004 - Add a Specialized test

- Source: Test catalog; Stage 1 TZ
- Source section: catalog paragraphs 6 and 12; Stage 1 TZ paragraph 57
- Type: `ADD`
- Requirement: `+ Тест` opens the Specialized catalog and allows a master to add a selected test with one action.
- Acceptance: A Specialized test is added only after selection and is then represented by its configured type.
- Dependencies: `REQ-MED-1B-CATALOG-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-005 - Search the catalog

- Source: Stage 1 TZ; current task instruction
- Source section: Stage 1 TZ paragraph 57; catalog form behavior
- Type: `ADD`
- Requirement: The `+ Тест` chooser supports search over the shared catalog.
- Acceptance: A master finds a Specialized test by configured title/number and adds the intended row without scanning an unbounded list.
- Dependencies: `REQ-MED-1B-COMMON-004`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-006 - Before and after columns

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rule paragraph 7; Stage 1 TZ paragraph 58
- Type: `ADD`
- Requirement: Every session test has `До сеанса` and `После сеанса` columns, and after uses the same type-specific fields.
- Acceptance: A master can enter before and after independently; after is not a different or unstructured form.
- Dependencies: Generic type REQs.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-007 - Index 0-3 buttons

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rule paragraph 8; Stage 1 TZ paragraph 59
- Type: `ADD`
- Requirement: Where a catalog type enables an index, the session uses large `0`, `1`, `2`, `3` buttons.
- Acceptance: Each enabled index is selectable and persisted for before and after; disabled-index tests do not display an index control.
- Dependencies: `REQ-MED-1B-CATALOG-001`; `REQ-MED-1A-CORE-008`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-008 - Index 0 is normal and hides abnormal inputs

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rule paragraph 8; Stage 1 TZ paragraph 59
- Type: `CHANGE`
- Requirement: Index `0` means normal. Type-specific abnormal fields such as tone and causes are hidden or not required at index 0.
- Acceptance: Selecting `0` hides the abnormal inputs, does not count a violation, and preserves no stale abnormal selection as an active result.
- Dependencies: `REQ-MED-1B-COMMON-007`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-009 - Index 1-3 opens needed fields

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rule paragraph 8; Stage 1 TZ paragraph 59
- Type: `ADD`
- Requirement: Index `1`, `2`, or `3` opens the type-specific fields required for that test, including hypo/hyper and causes where configured.
- Acceptance: The fields appear only when needed and remain linked to the correct row/side/position.
- Dependencies: `REQ-MED-1B-COMMON-007`; generic type REQs.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-010 - Multi-select causes as chips

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rule paragraph 9; Stage 1 TZ paragraphs 60-62
- Type: `ADD`
- Requirement: Causes are selected from the test's allowed shared tags using multi-select chips.
- Acceptance: Multiple causes can be attached to one row and are individually removable without editing free text.
- Dependencies: `REQ-MED-1B-TAG-001` through `REQ-MED-1B-TAG-006`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-011 - One primary and accompanying causes

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rule paragraph 9; Stage 1 TZ paragraph 62
- Type: `ADD`
- Requirement: One selected cause can be marked primary; all other selected causes are accompanying.
- Acceptance: A row cannot have two primary causes; changing the primary marker reclassifies the old one without deleting it.
- Dependencies: `REQ-MED-1B-COMMON-010`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-012 - Copy causes with `Также`

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rule paragraph 9; Stage 1 TZ paragraph 63
- Type: `ADD`
- Requirement: `Также` copies the causes from the row immediately above into the current row.
- Acceptance: The copied causes are editable in the current row and do not mutate the source row.
- Dependencies: `REQ-MED-1B-COMMON-010`; row ordering.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-013 - Add/search a cause from a test row

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rule paragraph 9; Stage 1 TZ paragraphs 64 and 94-97
- Type: `ADD`
- Requirement: `+` searches the global cause database and, when needed, creates a new tag and offers `Применить к...`.
- Acceptance: The master cannot create an obvious duplicate without seeing the existing global result; a new tag can be applied to selected tests.
- Dependencies: `REQ-MED-1B-TAG-011`, `REQ-MED-1B-TAG-012`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-014 - Show the previous after result in grey

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rule paragraph 10; Stage 1 TZ paragraph 66
- Type: `ADD`
- Requirement: The same test's previous `после` result is shown next to the current row in grey.
- Acceptance: The grey value is visibly historical and cannot be mistaken for the current before value.
- Dependencies: Historical session compatibility.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-015 - Automatically add previously violated tests

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rule paragraph 10; Stage 1 TZ paragraph 67
- Type: `ADD`
- Requirement: A test with a violation in the previous session is automatically added to the next session.
- Acceptance: The test is present even if it is not in the current default Main set, and it is not duplicated if already present.
- Dependencies: Violation rules; `REQ-MED-1B-COMMON-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-016 - Mark prior violations for recheck

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rule paragraph 10; Stage 1 TZ paragraph 67
- Type: `ADD`
- Requirement: Automatically carried tests display `перепроверить`.
- Acceptance: The label is visible on the carried test and disappears or updates according to the new result state without changing the historical result.
- Dependencies: `REQ-MED-1B-COMMON-015`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COMMON-017 - Enter every new before value again

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rule paragraph 10; Stage 1 TZ paragraph 68
- Type: `CHANGE`
- Requirement: A carried test's new `до` value starts empty and must be entered again; the old after is historical only.
- Acceptance: The new before input is not prefilled from the prior after, while the grey prior after remains visible.
- Dependencies: `REQ-MED-1B-COMMON-014`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### Counters

### REQ-MED-1B-COUNTER-001 - Violations before

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rules; Stage 1 TZ paragraphs 74-77
- Type: `ADD`
- Requirement: Show the number of violations before the session, computed by each configured type rule.
- Acceptance: A manual cross-check over a mixed set of test types matches the displayed before count.
- Dependencies: Generic type REQs; `REQ-MED-1B-COMMON-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COUNTER-002 - Violations after

- Source: Test catalog; Stage 1 TZ
- Source section: catalog common rules; Stage 1 TZ paragraphs 74-77
- Type: `ADD`
- Requirement: Show the number of violations after the session, computed by each configured type rule.
- Acceptance: A manual cross-check matches the displayed after count.
- Dependencies: `REQ-MED-1B-COUNTER-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COUNTER-003 - Resolved violations

- Source: Stage 1 TZ
- Source section: `В. Тесты`, paragraph 76
- Type: `ADD`
- Requirement: Show how many violations resolved from before to after.
- Acceptance: A violation that is normal after is counted as resolved according to the type rule; a newly introduced after violation is not counted as resolved.
- Dependencies: `REQ-MED-1B-COUNTER-001`; `REQ-MED-1B-COUNTER-002`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COUNTER-004 - New after violations are separate

- Source: Stage 1 TZ
- Source section: `В. Тесты`, paragraph 77
- Type: `ADD`
- Requirement: Show how many new violations appeared after the session; new violations are not subtracted from resolved.
- Acceptance: The manual counter example demonstrates separate resolved and new values even when both occur in the same session.
- Dependencies: `REQ-MED-1B-COUNTER-003`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-COUNTER-005 - Counters use only added session tests

- Source: Test catalog; Stage 1 TZ
- Source section: catalog paragraph 6; Stage 1 TZ paragraph 77
- Type: `CHANGE`
- Requirement: All four before/after/resolved/new counter calculations include only tests added to the session.
- Acceptance: Removing a Main test or leaving a Specialized test unadded changes no counter except by removing its session row.
- Dependencies: `REQ-MED-1B-COMMON-001`; `REQ-MED-1B-COMMON-003`; `REQ-MED-1B-COMMON-004`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

## Generic form types T1-T12

### REQ-MED-1B-TYPE-001 - T1 tone by sides

- Source: Test catalog
- Source section: type table row T15.2
- Type: `ADD`
- Requirement: T1 configures zones/rows and L/R sides. Each row has index 0-3; index 1-3 exposes `гипо` or `гипер` and causes. The system can derive a tag such as `[L_рука_гипотонус]`. Every row with index 1-3 is a violation.
- Acceptance: A T1 form renders configured rows/sides, hides tone/causes at 0, stores them at 1-3, and counts each abnormal row once.
- Dependencies: Common index/cause behavior.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TYPE-002 - T2 method computer

- Source: Test catalog
- Source section: type table row T15.3
- Type: `ADD`
- Requirement: T2 selects causes from the test list, allows one primary cause, and has a general index 0-3. Index 1-3 is a violation.
- Acceptance: A T2 row stores the selected causes and index and contributes one violation when the index is 1-3.
- Dependencies: `REQ-MED-1B-COMMON-007` through `REQ-MED-1B-COMMON-011`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TYPE-003 - T3 palpation by segments

- Source: Test catalog
- Source section: type table row T15.4
- Type: `ADD`
- Requirement: T3 records each vertebra/point as `смещение R`, `смещение L`, or `спазм`; section index 0-3 and causes are set per department. Every marked segment/point is a violation.
- Acceptance: The form stores each marked segment/point independently and counts marked items, not only the department index.
- Dependencies: Cause and segment catalog configuration.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TYPE-004 - T4 zone pattern

- Source: Test catalog
- Source section: type table row T15.5
- Type: `ADD`
- Requirement: T4 has four zones, each L or R. It displays the formula, highlights broken alternation, and lets the master mark a correction location. Normal patterns are `L-R-L-R` and `R-L-R-L`; there is no index. Violations are broken transitions.
- Acceptance: The configured four choices produce a deterministic formula and highlight each broken transition; correction location is stored; the count follows the catalog proposal and OQ-004.
- Dependencies: `REQ-MED-CATALOG-OQ-004`; `REQ-MED-1B-SPECIAL-002`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TYPE-005 - T5 yes/no by positions

- Source: Test catalog
- Source section: type table row T15.6
- Type: `ADD`
- Requirement: T5 records Yes/No for configured positions, with an optional side. Each Yes is a violation.
- Acceptance: A configured position can be answered independently; side is present only where configured; each Yes contributes one violation.
- Dependencies: Position catalog configuration.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TYPE-006 - T6 local pain point

- Source: Test catalog
- Source section: type table row T15.7
- Type: `ADD`
- Requirement: T6 records pain region, provoking movement, pain intensity 0-10, therapeutic-localization points, TL pain-reduction percentage, and index 0-3. Index 1-3 is a violation.
- Acceptance: The row stores all configured inputs and validates intensity/percentage according to approved configuration without inventing an AI threshold.
- Dependencies: `REQ-MED-CATALOG-OQ-005`; common index behavior.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TYPE-007 - T7 muscle/segment

- Source: Test catalog
- Source section: type table row T15.8
- Type: `ADD`
- Requirement: T7 configures a muscle with its derived segment and records L/R. Every marked side is a violation.
- Acceptance: The derived segment is shown from catalog configuration and each marked side is counted independently.
- Dependencies: `REQ-MED-CATALOG-OQ-001`; muscle catalog.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TYPE-008 - T8 traction

- Source: Test catalog
- Source section: type table row T15.9
- Type: `ADD`
- Requirement: T8 configures spinal departments with `нормотонус` or `гипотонус` and index 0-3 per department. Each hypotone department is a violation and shows `вытяжение в этом отделе не делать`.
- Acceptance: Index is independently stored per department; hypotone produces the warning; a normal department does not.
- Dependencies: `REQ-MED-1B-SPECIAL-003`; `REQ-MED-CATALOG-OQ-010`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TYPE-009 - T9 angle and mobility

- Source: Test catalog
- Source section: type table row T15.10
- Type: `ADD`
- Requirement: T9 configures R/L where applicable, accepts a 0-180 angle where configured, and records mobility index 0-3. Each side with index 1-3 is a violation.
- Acceptance: Side and angle semantics come from catalog configuration and index/angle are independent structured values.
- Dependencies: `REQ-MED-CATALOG-OQ-009`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TYPE-010 - T10 visual map

- Source: Test catalog
- Source section: type table row T15.11
- Type: `ADD`
- Requirement: T10 supports front, side, and back projections with configured zones, deviation options, and index 0-3. Each zone with index 1-3 is a violation.
- Acceptance: The three projections render their configured zones and deviations, and each zone with index 1-3 is counted as a violation.
- Dependencies: Test 2 configuration.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TYPE-011 - T11 findings list

- Source: Test catalog
- Source section: type table row T15.12
- Type: `ADD`
- Requirement: T11 records configurable findings such as organs, nerve branches, chains, psychosomatics, and thin-plane items. Where configured, it has overall Yes/No and causes. Each marked item is a violation.
- Acceptance: Findings are catalog-configurable, marked items are individually persisted/countable, and a generic example does not override test-specific counts such as Test 28's 34 organs.
- Dependencies: `REQ-MED-1B-CATALOG-001`; test-specific test 20, 26-30 REQs.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TYPE-012 - T12 energy structures

- Source: Test catalog
- Source section: type table row T15.13
- Type: `ADD`
- Requirement: T12 records energy potential 0-1000, chakras 1-7, tor direction R/L, and causes. Its violation rule remains configurable while catalog OQ-002 is open.
- Acceptance: The three structured subtests are stored independently and the configured violation rule can change without code deployment.
- Dependencies: `REQ-MED-CATALOG-OQ-002`; Test 31 REQ.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

## Required special rules

### REQ-MED-1B-SPECIAL-001 - Test 1 standing and lying are one test

- Source: Test catalog; Stage 1 TZ
- Source section: catalog paragraph 11 and test row T18.2; Stage 1 TZ paragraph 69
- Type: `ADD`
- Requirement: Test 1 is one test with standing and lying positions shown side by side. Each position has R/L values and both use the same 21-tag cause set.
- Acceptance: The session has one Test 1 identity, two position subviews, shared causes, and no duplicate test counter.
- Dependencies: `REQ-MED-1B-TEST-001`; `REQ-MED-CATALOG-OQ-012`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-SPECIAL-002 - Test 8 formula and correction point

- Source: Test catalog; Stage 1 TZ
- Source section: catalog T15.5 and T18.9; Stage 1 TZ paragraph 72
- Type: `ADD`
- Requirement: Test 8 automatically builds the four-zone formula, highlights broken alternation, and stores a correction point.
- Acceptance: For `L-L-R-L`, the broken transition is highlighted; for `L-L-L-L`, three broken transitions are countable under the source proposal.
- Dependencies: `REQ-MED-1B-TYPE-004`; `REQ-MED-CATALOG-OQ-004`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-SPECIAL-003 - Test 10 hypotone warning

- Source: Test catalog; Stage 1 TZ
- Source section: catalog T15.9 and T18.11; Stage 1 TZ paragraph 71
- Type: `ADD`
- Requirement: Test 10 displays the yellow warning `вытяжение в этом отделе не делать` for each department marked hypotone.
- Acceptance: The warning is local to the hypotone department and is absent for normotone departments.
- Dependencies: `REQ-MED-1B-TYPE-008`; `REQ-MED-CATALOG-OQ-010`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-SPECIAL-004 - Persistent Test 18 safety marker

- Source: Stage 1 TZ; current task instruction
- Source section: Stage 1 TZ paragraph 70; Test 18 catalog row
- Type: `ADD`
- Requirement: Any Yes in Test 18 sets a persistent red safety marker in the client header and session: `Жёсткие манипуляции, особенно с шеей, запрещены`. The marker remains in following sessions until the master removes it. Removal records date and reason, and the marker text is editable.
- Acceptance: A Yes creates the marker in both required headers; a later session still shows it; an authorized master can clear it only with date/reason evidence; the exact displayed text can be configured without changing historical marker evidence.
- Dependencies: `REQ-MED-1B-TEST-018`; `REQ-MED-1A-PROFILE-005`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-SPECIAL-005 - Test 19 violation type

- Source: Test catalog; Stage 1 TZ
- Source section: catalog test row T18.20; Stage 1 TZ paragraph 73
- Type: `ADD`
- Requirement: Test 19 stores `тип нарушения` as one of `механическое` or `информационное`.
- Acceptance: The type is selectable, persisted per applicable result, and remains distinct from causes and violation count.
- Dependencies: `REQ-MED-1B-TEST-019`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

## The 31 configured tests

Each test below is a separate REQ. The `Source configuration` line preserves the exact catalog row; cause-list details not printed in the 14.11 catalog remain references to the supplied 03.11 source and are not invented here.

### REQ-MED-1B-TEST-001 - Myotatic reflex, shoulder flexor

- Source: Test catalog
- Source section: test table row T18.2, test 1
- Type: `ADD`
- Requirement: Test 1 is T1, Main, one test with standing and lying positions side by side, R/L in each position, and the shared 21-cause set.
- Acceptance: The master enters both positions in one test; T1 row violations are counted per configured row; causes are shared across positions.
- Dependencies: `REQ-MED-1B-TYPE-001`; `REQ-MED-1B-SPECIAL-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-002 - Visual body and posture diagnosis

- Source: Test catalog
- Source section: test table row T18.3, test 2
- Type: `ADD`
- Requirement: Test 2 is T10, Main: front has 7 zones, side has 6 zones including `общий гравитационный вектор` without an index, and back has 8 zones.
- Acceptance: The three projections render the exact source counts; deviation options/indexes follow the configured T10 rule and the gravity-vector row has no index.
- Dependencies: `REQ-MED-1B-TYPE-010`; `REQ-MED-CATALOG-OQ-008`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-003 - Shoulder standing test

- Source: Test catalog
- Source section: test table row T18.4, test 3
- Type: `ADD`
- Requirement: Test 3 is T1, Main, shoulder standing with arm to the side, L/R, and 23 cause tags from the source list.
- Acceptance: The form renders L/R rows with the configured 23-cause set and T1 violation behavior.
- Dependencies: `REQ-MED-1B-TYPE-001`; `REQ-MED-1B-TAG-006`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-004 - Scapula standing test

- Source: Test catalog
- Source section: test table row T18.5, test 4
- Type: `ADD`
- Requirement: Test 4 is T1, Main, scapula standing with arms at 90 degrees, L/R, and 23 cause tags from the source list.
- Acceptance: The form renders L/R rows with the configured 23-cause set and T1 violation behavior.
- Dependencies: `REQ-MED-1B-TYPE-001`; `REQ-MED-1B-TAG-006`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-005 - Gait pattern

- Source: Test catalog
- Source section: test table row T18.6, test 5
- Type: `ADD`
- Requirement: Test 5 is T1, Main, with four combinations: R leg + R arm, R leg + L arm, L leg + L arm, L leg + R arm, and 23 cause tags.
- Acceptance: All four combinations are independently fillable and use T1 counting and the configured cause list.
- Dependencies: `REQ-MED-1B-TYPE-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-006 - Push provocation tests

- Source: Test catalog
- Source section: test table row T18.7, test 6
- Type: `ADD`
- Requirement: Test 6 is T1, Main, with 6 zones x L/R: skull base, shoulders, scapulae, diaphragm, pelvis, and hip joint. Each zone has 2-6 own causes plus the common list. Both hypo and hyper are allowed in every zone.
- Acceptance: The form renders each configured zone/side, supports both tone values, and uses the zone-specific plus common cause sets.
- Dependencies: `REQ-MED-1B-TYPE-001`; `REQ-MED-CATALOG-OQ-007`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-007 - Body palpation

- Source: Test catalog
- Source section: test table row T18.8, test 7
- Type: `ADD`
- Requirement: Test 7 is T3, Main: 7.1 neck C0-C8; 7.2 thoracic Th1-Th12; 7.3 lumbar L1-L5; 7.4 sciatic nerve with 7 points x R/L and source cause count 6; 7.5 PPM R/L and source cause count 9.
- Acceptance: Each segment/point is individually markable as shift R, shift L, or spasm, with department index/causes and T3 violation counting.
- Dependencies: `REQ-MED-1B-TYPE-003`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-008 - Myozonal test

- Source: Test catalog
- Source section: test table row T18.9, test 8
- Type: `ADD`
- Requirement: Test 8 is T4, Specialized: skull base, ШГП, ГПП, ПКП each choose L/R, plus correction location.
- Acceptance: The four choices build the source formula and apply the T4 alternation/highlight/count rule.
- Dependencies: `REQ-MED-1B-TYPE-004`; `REQ-MED-1B-SPECIAL-002`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-009 - Global pain-point test

- Source: Test catalog
- Source section: test table row T18.10, test 9
- Type: `ADD`
- Requirement: Test 9 is T5 + T6, Main. Variant 9.1 has standing R, standing L, sitting, supine, supine with open mouth, and supine with clenched mouth. Variant 9.2 has 12 regions and 14 provoking points.
- Acceptance: Both variants are represented in one catalog test and can store T5/T6 fields, including movement and TL pain-reduction percentage.
- Dependencies: `REQ-MED-1B-TYPE-005`; `REQ-MED-1B-TYPE-006`; `REQ-MED-CATALOG-OQ-005`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-010 - Sitting traction test

- Source: Test catalog
- Source section: test table row T18.11, test 10
- Type: `ADD`
- Requirement: Test 10 is T8, Specialized, with ШОП, ГОП, and ПОП departments.
- Acceptance: Each department has its own normotone/hypotone and index result and triggers the specified warning when hypotone.
- Dependencies: `REQ-MED-1B-TYPE-008`; `REQ-MED-1B-SPECIAL-003`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-011 - Neck method computer

- Source: Test catalog
- Source section: test table row T18.12, test 11
- Type: `ADD`
- Requirement: Test 11 is T2, Specialized, with the source cause list count 25.
- Acceptance: The T2 form uses the configured 25 cause references and one primary cause.
- Dependencies: `REQ-MED-1B-TYPE-002`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-012 - Lumbar method computer

- Source: Test catalog
- Source section: test table row T18.13, test 12
- Type: `ADD`
- Requirement: Test 12 is T2, Specialized, with the source cause list count 27.
- Acceptance: The T2 form uses the configured 27 cause references and one primary cause.
- Dependencies: `REQ-MED-1B-TYPE-002`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-013 - Pelvis method computer

- Source: Test catalog
- Source section: test table row T18.14, test 13
- Type: `ADD`
- Requirement: Test 13 is T2, Specialized, with the source cause list count 26.
- Acceptance: The T2 form uses the configured 26 cause references and one primary cause.
- Dependencies: `REQ-MED-1B-TYPE-002`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-014 - Sacrum method computer

- Source: Test catalog
- Source section: test table row T18.15, test 14
- Type: `ADD`
- Requirement: Test 14 is T2, Specialized, with the source cause list count 17.
- Acceptance: The T2 form uses the configured 17 cause references and one primary cause.
- Dependencies: `REQ-MED-1B-TYPE-002`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-015 - Body diaphragms

- Source: Test catalog
- Source section: test table row T18.16, test 15
- Type: `ADD`
- Requirement: Test 15 is T1, Specialized: 15.1 pelvic R/L with source cause count 13; 15.2 ГБД R/L, legs, and method A/B/C with source cause count 16; 15.3 thoracic R/L with source cause count 15. The proposed 15.2 method is one selected method with one result.
- Acceptance: The subtests and cause references are separate, 15.2 stores which method was used, and T1 violation behavior applies.
- Dependencies: `REQ-MED-1B-TYPE-001`; `REQ-MED-CATALOG-OQ-006`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-016 - Lumbar/sacral nerve compression

- Source: Test catalog
- Source section: test table row T18.17, test 16
- Type: `ADD`
- Requirement: Test 16 is T7, Specialized, with 5 muscles: L1-L2, L2-L4, L5-S1, L5, and S1-S2. The per-side index semantics remain catalog OQ-001.
- Acceptance: Each configured muscle and side can be recorded and contributes according to the resolved T7 rule.
- Dependencies: `REQ-MED-1B-TYPE-007`; `REQ-MED-CATALOG-OQ-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-017 - Neck nerve compression

- Source: Test catalog
- Source section: test table row T18.18, test 17
- Type: `ADD`
- Requirement: Test 17 is T7, Specialized, with 7 muscles: C1-C3, C4, C5, C6, C7, C8, and Th1. The per-side index semantics remain catalog OQ-001.
- Acceptance: Each configured muscle and side can be recorded and contributes according to the resolved T7 rule.
- Dependencies: `REQ-MED-1B-TYPE-007`; `REQ-MED-CATALOG-OQ-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-018 - Cervical spinal-cord compression

- Source: Test catalog; Stage 1 TZ
- Source section: test table row T18.19, test 18; Stage 1 TZ paragraph 70
- Type: `ADD`
- Requirement: Test 18 is T5, Specialized. Sitting checks quadriceps plus triceps on L/R; lying checks chin to neck. Any Yes creates the persistent safety marker.
- Acceptance: Every configured Yes creates the marker in the client header and session, persists to later sessions, and supports authorized clear with date/reason and editable marker text.
- Dependencies: `REQ-MED-1B-TYPE-005`; `REQ-MED-1B-SPECIAL-004`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-019 - Dura-mater twisting

- Source: Test catalog; Stage 1 TZ
- Source section: test table row T18.20, test 19; Stage 1 TZ paragraph 73
- Type: `ADD`
- Requirement: Test 19 is T2 + T5: 19.1 method computer with source cause count 10; 19.2 thoracic twist with 2 combinations; 19.3 four neurological tests. It stores mechanical/informational violation type.
- Acceptance: The subtests use their configured form types and the type field is persisted independently.
- Dependencies: `REQ-MED-1B-TYPE-002`; `REQ-MED-1B-TYPE-005`; `REQ-MED-1B-SPECIAL-005`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-020 - Myofascial chains

- Source: Test catalog
- Source section: test table row T18.21, test 20
- Type: `ADD`
- Requirement: Test 20 is T11, Specialized, with five chains: deep, lateral, front, back, spiral. Each uses Yes/No, configured muscles, and chain cause list. The result has one selected `приоритетная цепь` from the five.
- Acceptance: Each chain's findings/causes are stored, every marked item counts as a violation, and exactly one priority chain can be selected.
- Dependencies: `REQ-MED-1B-TYPE-011`; `REQ-MED-1B-ROOT-002`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-021 - TMJ method computer

- Source: Test catalog
- Source section: test table row T18.22, test 21
- Type: `ADD`
- Requirement: Test 21 is T2, Specialized, with source cause list count 12.
- Acceptance: The T2 form uses the configured 12 cause references and one primary cause.
- Dependencies: `REQ-MED-1B-TYPE-002`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-022 - Skull method computer

- Source: Test catalog
- Source section: test table row T18.23, test 22
- Type: `ADD`
- Requirement: Test 22 is T2, Specialized, with 8 skull bones and 4 causes. The index absence remains catalog OQ-003.
- Acceptance: Each configured bone/result is represented using the T2 cause behavior without an index unless the open question is later changed in catalog configuration.
- Dependencies: `REQ-MED-1B-TYPE-002`; `REQ-MED-CATALOG-OQ-003`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-023 - Abdominal muscles

- Source: Test catalog
- Source section: test table row T18.24, test 23
- Type: `ADD`
- Requirement: Test 23 is T1, Specialized: 23.1 rectus with source cause count 9; 23.2 external obliques R/L with source cause count 11; 23.3 internal obliques R/L with source cause count 12.
- Acceptance: All subtests and sides are independently fillable with T1 index/tone/cause behavior.
- Dependencies: `REQ-MED-1B-TYPE-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-024 - Pelvic muscles

- Source: Test catalog
- Source section: test table row T18.25, test 24
- Type: `ADD`
- Requirement: Test 24 is T1, Specialized: 24.1 gluteus maximus R/L; 24.2 pelvic diaphragm R/L with posterior, middle, and anterior bundles; 24.3 piriformis R/L; 24.4 gemelli R/L.
- Acceptance: Each subtest, side, and pelvic-diaphragm bundle is configurable and independently stored.
- Dependencies: `REQ-MED-1B-TYPE-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-025 - Biomechanical mobility tests

- Source: Test catalog; catalog questions
- Source section: test table row T18.26, test 25; question row T21.10
- Type: `ADD`
- Requirement: Test 25 is T9, Main, with 25.1 knees, 25.2 hip joints, 25.3 shoulders, 25.4 elbows, 25.5 scapulae, 25.6 lumbar bend, and 25.7 ankle. Source semantics: 25.6 has angle plus index and, for a forward bend, no side; 25.7 has only index and proposal/default R/L. Final side semantics remain configurable through OQ-009.
- Acceptance: The exact subtests render; angle is 0-180 where configured; 25.6/25.7 side behavior can be changed through catalog configuration without code deployment.
- Dependencies: `REQ-MED-1B-TYPE-009`; `REQ-MED-CATALOG-OQ-009`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-026 - Vagus nerve

- Source: Test catalog
- Source section: test table row T18.27, test 26
- Type: `ADD`
- Requirement: Test 26 is T11, Specialized, with Yes/No and 18 locations including nuclei, branches, and organs. Index absence remains catalog OQ-003.
- Acceptance: Each of the 18 configured locations is independently markable and each marked item is a violation.
- Dependencies: `REQ-MED-1B-TYPE-011`; `REQ-MED-CATALOG-OQ-003`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-027 - Phrenic nerve

- Source: Test catalog
- Source section: test table row T18.28, test 27
- Type: `ADD`
- Requirement: Test 27 is T11, Specialized, with Yes/No and 9 locations. Index absence remains catalog OQ-003.
- Acceptance: Each of the 9 configured locations is independently markable and each marked item is a violation.
- Dependencies: `REQ-MED-1B-TYPE-011`; `REQ-MED-CATALOG-OQ-003`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-028 - Internal organs

- Source: Test catalog
- Source section: test table row T18.29, test 28
- Type: `ADD`
- Requirement: Test 28 is T11, Specialized, with 34 organs and 10 causes. Index absence remains catalog OQ-003. The test-specific count `34` is authoritative for this row even though the generic T11 table gives a separate 20-item example.
- Acceptance: The catalog can configure all 34 organs, 10 cause references, and per-item violation counting without inventing the missing organ/cause names.
- Dependencies: `REQ-MED-1B-TYPE-011`; `REQ-MED-CATALOG-OQ-003`; supplied source cause list.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-029 - Psychosomatics

- Source: Test catalog
- Source section: test table row T18.30, test 29
- Type: `ADD`
- Requirement: Test 29 is T11, Specialized, with 9 items and a nested `непроработанные чувства` list of 16 feelings. Index absence remains catalog OQ-003.
- Acceptance: The nested 16-item list is configurable and each marked item contributes according to T11.
- Dependencies: `REQ-MED-1B-TYPE-011`; `REQ-MED-CATALOG-OQ-003`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-030 - Thin plane

- Source: Test catalog
- Source section: test table row T18.31, test 30
- Type: `ADD`
- Requirement: Test 30 is T11, Specialized, method computer with 11 items. Index absence remains catalog OQ-003.
- Acceptance: The 11 configured items are independently markable and counted using T11.
- Dependencies: `REQ-MED-1B-TYPE-011`; `REQ-MED-CATALOG-OQ-003`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TEST-031 - Energy structures

- Source: Test catalog; catalog questions
- Source section: test table row T18.32, test 31; question row T21.3
- Type: `ADD`
- Requirement: Test 31 is T12, Main for subtest 31.1: energy potential 0-1000; 31.2 chakras 1-7; 31.3 tor field rotation R/L. Each subtest has source cause count 5. Violation semantics, threshold, and normal tor direction remain OQ-002.
- Acceptance: The new-session default includes 31.1 only; the catalog can configure all 31 subtests and change the business violation rule without code deployment after Женя answers OQ-002.
- Dependencies: `REQ-MED-1B-TYPE-012`; `REQ-MED-CATALOG-OQ-002`; `REQ-MED-1B-COMMON-002`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

## Shared cause/tag database

### REQ-MED-1B-TAG-001 - One shared cause database

- Source: Stage 1 TZ
- Source section: `Г. База тегов-причин`, paragraphs 80-81
- Type: `ADD`
- Requirement: All tests use one shared cause/tag database. The same cause, such as `ТМО`, is one tag across all tests.
- Acceptance: A tag selected in Test 3 can be found and reused in Test 12 without creating a second identity.
- Dependencies: `REQ-MED-1A-CORE-003`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TAG-002 - Russian names and synonyms

- Source: Stage 1 TZ
- Source section: `Г.`, paragraphs 82-85
- Type: `ADD`
- Requirement: A tag has a Russian name and searchable synonyms, such as `стопа` and `стопы`.
- Acceptance: Search finds the canonical tag from its configured synonyms without creating a duplicate.
- Dependencies: `REQ-MED-1B-TAG-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TAG-003 - Cause groups

- Source: Stage 1 TZ
- Source section: `Г.`, paragraph 85
- Type: `ADD`
- Requirement: A tag can belong to `физика и структура`, `висцера`, `нервы и оболочки`, or `поле и психика`; the group can remain empty initially.
- Acceptance: An authorized user can set or leave the group empty without inventing a clinical classification.
- Dependencies: `REQ-MED-1B-TAG-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TAG-004 - Cause tooltip

- Source: Stage 1 TZ
- Source section: `Г.`, paragraph 86
- Type: `ADD`
- Requirement: A tag can have a hover tooltip with abbreviation/help text, such as `ГБД - грудо-брюшная диафрагма`.
- Acceptance: The tooltip is available at the tag control without changing the canonical tag name.
- Dependencies: `REQ-MED-1B-TAG-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TAG-005 - Active and moderation status

- Source: Stage 1 TZ
- Source section: `Г.`, paragraph 87
- Type: `ADD`
- Requirement: A tag has status `активен` or `на модерации`.
- Acceptance: Status controls catalog availability without deleting historical references.
- Dependencies: `REQ-MED-1B-TAG-010`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TAG-006 - Test usage mapping

- Source: Stage 1 TZ
- Source section: `Г.`, paragraph 88
- Type: `ADD`
- Requirement: Each tag records or exposes the tests in which it is allowed/used.
- Acceptance: A test row shows only its configured allowed tags, while global search can still find the shared identity.
- Dependencies: `REQ-MED-1B-CATALOG-001`; `REQ-MED-1B-TAG-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TAG-007 - Distinguish the AI cause tag

- Source: Stage 1 TZ
- Source section: `Г.`, paragraph 89
- Type: `CHANGE`
- Requirement: The cause tag `ИИ` is displayed as `ИИ (искусственный интеллект)` and means the cause, not the platform assistant.
- Acceptance: The label is unambiguous in tag controls, history, root causes, and result aggregation.
- Dependencies: `REQ-MED-1B-TAG-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TAG-008 - Admin tag lifecycle

- Source: Stage 1 TZ
- Source section: `Г.`, paragraphs 90-91
- Type: `ADD`
- Requirement: Authorized Женя, Денис, and Иван can create, rename, and hide tags; merge is handled by the dedicated merge REQ.
- Acceptance: Each lifecycle action is available through CRM authorization and leaves historical references readable.
- Dependencies: `REQ-MED-1A-CORE-005`; `REQ-MED-1B-TAG-005`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TAG-009 - Future-master tag proposal

- Source: Stage 1 TZ; stages document
- Source section: `Г.`, paragraph 92; Stage 4 paragraph 77
- Type: `ADD`
- Requirement: A future non-admin master can propose a tag; it works immediately for that master according to source behavior, then enters common moderation for Женя's approval.
- Acceptance: The proposed tag is available to the proposing master, marked `на модерации`, and not silently treated as globally approved.
- Dependencies: `REQ-MED-1B-TAG-005`; authorization model.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TAG-010 - Merge preserves and reassigns history

- Source: Stage 1 TZ
- Source section: `Г.`, paragraph 93
- Type: `CHANGE`
- Requirement: Merging duplicate tags reassigns existing references to the surviving tag and preserves historical sessions.
- Acceptance: After a merge, old sessions show the surviving canonical tag; no historical mark disappears or is duplicated.
- Dependencies: `REQ-MED-COMPAT-004`; PostgreSQL implementation verification.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TAG-011 - Global search before create

- Source: Stage 1 TZ
- Source section: `Г.`, paragraphs 94-96
- Type: `ADD`
- Requirement: The test-row `+` action searches the entire cause DB before offering `Создать тег`.
- Acceptance: Existing canonical names/synonyms appear before create; a genuinely new tag can be created by an authorized actor.
- Dependencies: `REQ-MED-1B-TAG-002`; `REQ-MED-1B-TAG-008`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TAG-012 - Apply a new tag to selected tests

- Source: Stage 1 TZ
- Source section: `Г.`, paragraph 97
- Type: `ADD`
- Requirement: After creating a tag, `Применить к...` shows a multi-test checklist so the master can add the tag to selected tests.
- Acceptance: The tag is added only to checked tests, remains one shared identity, and can be used immediately in the current workflow.
- Dependencies: `REQ-MED-1B-CATALOG-001`; `REQ-MED-1B-TAG-006`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-TAG-013 - Initial cause loading without invented semantics

- Source: Stage 1 TZ; current task instruction
- Source section: `Г.`, paragraph 98; cause/tag requirements
- Type: `ADD`
- Requirement: Initial loading imports the supplied catalog/source cause lists. Obvious spelling duplicates are normalized, including examples such as `Стопы`/`стопы`/`[Стопа]` and `Шрамы`/`Шрамы/Спайки`; `[TAG_DURA]` is represented as the supplied Russian `ТМО`. Ambiguous clinical meanings remain separate until Женя merges them. No medical semantics are invented.
- Acceptance: Initial loading records provenance and normalization decisions; ambiguous tags are not silently merged; missing source lists are not fabricated.
- Dependencies: `REQ-MED-1B-TAG-001` through `REQ-MED-1B-TAG-012`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

## Root causes

### REQ-MED-1B-ROOT-001 - Aggregate session causes

- Source: Stage 1 TZ
- Source section: `Д. Причины`, paragraphs 103-107
- Type: `ADD`
- Requirement: Aggregate all causes from session tests, deduplicate by shared tag identity, count the number of tests in which each appears, and record whether it was primary anywhere. Sort primary causes first, then by frequency.
- Acceptance: A session with repeated causes across multiple tests displays one cause row with the correct test count and primary flag; sorting is deterministic.
- Dependencies: `REQ-MED-1B-TAG-001`; structured test causes.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-ROOT-002 - Master-selectable primary/root causes

- Source: Stage 1 TZ
- Source section: `Д.`, paragraphs 108-110
- Type: `ADD`
- Requirement: The master selects one or several root causes from the aggregate list or global cause DB; exactly one can be marked main.
- Acceptance: Multiple causes are allowed, only one is main, and the selection is editable after aggregation.
- Dependencies: `REQ-MED-1B-ROOT-001`; `REQ-MED-1B-TAG-011`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-ROOT-003 - Influences relation and free text

- Source: Stage 1 TZ
- Source section: `Д.`, paragraphs 111-112
- Type: `ADD`
- Requirement: Root causes support optional `на это влияет` relations, such as `блуждающий нерв <- череп`, plus free text.
- Acceptance: A master can add or omit the relation and save explanatory text without replacing structured cause identities.
- Dependencies: `REQ-MED-1B-ROOT-002`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

## Result

### REQ-MED-1B-RESULT-001 - Deterministic editable result assembly

- Source: Stage 1 TZ
- Source section: `Д. Блок 7`, paragraphs 113-114
- Type: `ADD`
- Requirement: `Собрать` creates an editable deterministic summary. Stage 1 does not call AI for this action.
- Acceptance: The same saved session data produces the same initial summary, and the master can edit the resulting text without changing source records.
- Dependencies: Complaint/test/result/effectiveness histories.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-RESULT-002 - Result inputs and five source hints

- Source: Stage 1 TZ
- Source section: `Д. Блок 7`, paragraphs 114-125
- Type: `ADD`
- Requirement: The summary includes complaints `было X -> стало Y`; test violations before/after, resolved count, and tests that became 0; subjective feelings; posture changes; after-vitals when present. Show five source hints: pain; mobility; visual balance; discomfort/energy/sleep/appetite/experiences; test results.
- Acceptance: A session with each input produces the corresponding section and the five hints are visible as source guidance.
- Dependencies: `REQ-MED-1A-COMPLAINT-023`; counters; Stage 1C vitals; subjective/posture blocks.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-RESULT-003 - Confirm before overwriting a manual result

- Source: Stage 1 TZ
- Source section: `Д. Блок 7`, paragraph 126
- Type: `ADD`
- Requirement: A second `Собрать` asks for confirmation before replacing a manually edited result.
- Acceptance: Cancel preserves manual text; confirmation replaces it with a newly assembled deterministic summary.
- Dependencies: `REQ-MED-1B-RESULT-001`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

## Effectiveness

### REQ-MED-1B-EFFECT-001 - Component effectiveness counters

- Source: Stage 1 TZ
- Source section: `Д. Блок 9`, paragraphs 127-132
- Type: `ADD`
- Requirement: Show average pain reduction percentage only for complaint rows with both before and after; worsening is negative. Also show fully resolved complaints (`после = 0`), violations before, violations after, resolved, new, and unique-cause count.
- Acceptance: Manual examples match each displayed component; incomplete before/after rows are excluded from average pain reduction; worsening uses a minus sign.
- Dependencies: Structured complaints, test counters, shared causes.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-EFFECT-002 - No integrative effectiveness percentage in Stage 1

- Source: Stage 1 TZ; stages document
- Source section: `Д. Блок 9`, paragraph 133; Stage 2 paragraph 36
- Type: `EXCLUDE`
- Requirement: Do not implement one integrative efficiency/effectiveness percentage in Stage 1. Store all components so a Stage 2 formula can be applied retrospectively to old sessions.
- Acceptance: The Stage 1 UI exposes component values only and no combined percentage; the stored component data is sufficient for future retrospective calculation.
- Dependencies: `REQ-MED-1B-EFFECT-001`; `REQ-MED-2-INPUT-003`.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

## Recommendations

### REQ-MED-1B-RECOMMEND-001 - Explicit recommendation send

- Source: Stage 1 TZ
- Source section: `Д. Блок 10`, paragraphs 134-137
- Type: `ADD`
- Requirement: Block 10 has text and `Одобрить и отправить`. Nothing is sent without the explicit button. Delivery uses the Telegram bot and records sent date/status.
- Acceptance: Draft text remains unsent until the button is clicked; successful delivery shows status/date; a failed delivery does not claim sent state.
- Dependencies: Telegram channel adapter; session recommendation block.
- Delivery package: `1B`
- Status: `TO IMPLEMENT`

### REQ-MED-1B-RECOMMEND-002 - Recommendation history in client card and PDF

- Source: Stage 1 TZ
- Source section: `Д. Блок 10`, paragraph 138
- Type: `ADD`
- Requirement: Recommendations from all sessions are accessible in the client card and included in the Stage 1 PDF.
- Acceptance: The client-card history and PDF show each session's recommendations without including therapist notes, audio, or photos.
- Dependencies: `REQ-MED-1C-PDF-001`; `REQ-MED-1C-PDF-002`.
- Delivery package: `1B / 1C`
- Status: `TO IMPLEMENT`

## Catalog open questions, non-blocking

These 13 records preserve every question in the catalog. They do not block starting Stage 1 development. Until Женя answers, the `Proposal/default` value is used. Where the source requires configurability, Женя can change the business configuration through CRM without a code deployment. An answer must not be silently guessed or converted into medical doctrine.

### REQ-MED-CATALOG-OQ-001 - Sides and index for Tests 16 and 17

- Source: Test catalog
- Source section: catalog question table row T21.2, question 1
- Type: `CHANGE`
- Requirement: Decide what is recorded for each side in nerve-compression Tests 16 and 17.
- Acceptance: Proposal/default: L and R each have index buttons 0-3; 0 means the muscle holds, 1-3 means weakness. The final choice remains catalog configuration.
- Dependencies: `REQ-MED-1B-TEST-016`; `REQ-MED-1B-TEST-017`.
- Delivery package: `1B (proposal/default); Open Question bucket`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-CATALOG-OQ-002 - Test 31 violation rule and normal tor

- Source: Test catalog
- Source section: catalog question table row T21.3, question 2
- Type: `CHANGE`
- Requirement: Decide the energy-potential threshold, whether each marked chakra is a violation, and which tor direction is normal in Test 31.
- Acceptance: Proposal/default: below-threshold energy, each marked chakra, and incorrect tor direction are violations; Женя supplies the numeric threshold and normal direction. The rule is editable in catalog configuration.
- Dependencies: `REQ-MED-1B-TEST-031`; `REQ-MED-1B-TYPE-012`.
- Delivery package: `1B (proposal/default); Open Question bucket`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-CATALOG-OQ-003 - Index absence in Tests 19.1, 22, and 26-30

- Source: Test catalog
- Source section: catalog question table row T21.4, question 3
- Type: `CHANGE`
- Requirement: Decide whether these findings require a 0-3 index.
- Acceptance: Proposal/default: leave them without an index; each marked position is a violation, as the source states for the 20-organ example. Final behavior remains catalog configuration and test-specific counts remain authoritative.
- Dependencies: `REQ-MED-1B-TEST-019`; `REQ-MED-1B-TEST-022` through `REQ-MED-1B-TEST-030`.
- Delivery package: `1B (proposal/default); Open Question bucket`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-CATALOG-OQ-004 - T4 broken-transition count

- Source: Test catalog
- Source section: catalog question table row T21.5, question 4
- Type: `CHANGE`
- Requirement: Decide how many T4 violations to count when alternation is broken.
- Acceptance: Proposal/default: count broken transitions; `L-L-R-L` is one and `L-L-L-L` is three. The counting rule remains editable configuration.
- Dependencies: `REQ-MED-1B-TYPE-004`; `REQ-MED-1B-SPECIAL-002`.
- Delivery package: `1B (proposal/default); Open Question bucket`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-CATALOG-OQ-005 - Test 9.2 movement and TL percentage

- Source: Test catalog
- Source section: catalog question table row T21.6, question 5
- Type: `CHANGE`
- Requirement: Include movement of provocation in Test 9.2 and record TL pain reduction as a number.
- Acceptance: Proposal/default: movement options include standing, bending, rising from a chair, walking, and rest; the master enters the percentage, with any threshold deferred to Stage 2 AI.
- Dependencies: `REQ-MED-1B-TEST-009`; `REQ-MED-1B-TYPE-006`.
- Delivery package: `1B (proposal/default); Open Question bucket`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-CATALOG-OQ-006 - Test 15.2 method A/B/C semantics

- Source: Test catalog
- Source section: catalog question table row T21.7, question 6
- Type: `CHANGE`
- Requirement: Decide whether Test 15.2 methods A, B, and C are one selected method or three results.
- Acceptance: Proposal/default: record which method was used and one result. The selection/result model remains configurable.
- Dependencies: `REQ-MED-1B-TEST-015`.
- Delivery package: `1B (proposal/default); Open Question bucket`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-CATALOG-OQ-007 - Test 6 hypo/hyper options

- Source: Test catalog
- Source section: catalog question table row T21.8, question 7
- Type: `CHANGE`
- Requirement: Decide whether every Test 6 zone supports both hypotone and hypertone.
- Acceptance: Proposal/default: yes, both options are available in all zones; the catalog can configure the allowed options.
- Dependencies: `REQ-MED-1B-TEST-006`.
- Delivery package: `1B (proposal/default); Open Question bucket`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-CATALOG-OQ-008 - Test 2 back-view label

- Source: Test catalog
- Source section: catalog question table row T21.9, question 8
- Type: `CHANGE`
- Requirement: Name the last Test 2 back-view group containing heel valgus/varus, Achilles, and calves.
- Acceptance: Proposal/default: label it `Голень и стопа`; the label is editable catalog content.
- Dependencies: `REQ-MED-1B-TEST-002`.
- Delivery package: `1B (proposal/default); Open Question bucket`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-CATALOG-OQ-009 - Test 25.7 sides and 25.6 side semantics

- Source: Test catalog
- Source section: catalog question table row T21.10, question 9
- Type: `CHANGE`
- Requirement: Decide whether ankle Test 25.7 has R/L and whether lumbar bend Test 25.6 has no side when bending forward.
- Acceptance: Proposal/default: 25.7 has R/L; 25.6 has no side for forward bend. Both are catalog-configurable.
- Dependencies: `REQ-MED-1B-TEST-025`; `REQ-MED-1B-TYPE-009`.
- Delivery package: `1B (proposal/default); Open Question bucket`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-CATALOG-OQ-010 - Test 10 index granularity

- Source: Test catalog
- Source section: catalog question table row T21.11, question 10
- Type: `CHANGE`
- Requirement: Decide whether Test 10 has one index for the test or one index for each department.
- Acceptance: Proposal/default: one index per department; the department-level configuration drives warnings and counting.
- Dependencies: `REQ-MED-1B-TEST-010`; `REQ-MED-1B-TYPE-008`.
- Delivery package: `1B (proposal/default); Open Question bucket`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-CATALOG-OQ-011 - Exact Main set

- Source: Test catalog
- Source section: catalog question table row T21.12, question 11
- Type: `CHANGE`
- Requirement: Confirm the exact Main set and order.
- Acceptance: Proposal/default: `1, 2, 3, 4, 5, 6, 7, 9, 25, 31.1`; the set can be changed through catalog settings.
- Dependencies: `REQ-MED-1B-COMMON-002`.
- Delivery package: `1B (proposal/default); Open Question bucket`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-CATALOG-OQ-012 - Shared Test 1 cause list

- Source: Test catalog
- Source section: catalog question table row T21.13, question 12
- Type: `CHANGE`
- Requirement: Confirm the cause set shared by Test 1 standing and lying positions.
- Acceptance: Proposal/default: both use the 21-tag list, even though the source notes a shorter standing list. The shared set remains editable catalog data.
- Dependencies: `REQ-MED-1B-TEST-001`; `REQ-MED-1B-SPECIAL-001`.
- Delivery package: `1B (proposal/default); Open Question bucket`
- Status: `OPEN QUESTION - NON-BLOCKING`

### REQ-MED-CATALOG-OQ-013 - Pulse-wave type catalog

- Source: Test catalog; Stage 1 TZ
- Source section: catalog question table row T21.14, question 13; Stage 1 TZ table row 147.9
- Type: `CHANGE`
- Requirement: Женя provides the complete pulse-wave type list and human names; the master selects from the configurable list and attaches a device-screen photo.
- Acceptance: Proposal/default is the source placeholder (`Тип A / Тип B / Тип C`) until the authoritative list arrives. No final clinical names are invented.
- Dependencies: `REQ-MED-1C-VITALS-001`; `REQ-MED-1A-CORE-005`.
- Delivery package: `1C (proposal/default); Open Question bucket`
- Status: `OPEN QUESTION - NON-BLOCKING`

## Catalog source-to-REQ traceability

The rows below are the source-to-REQ traceability pass for the catalog DOCX. Each range is inclusive. The source's title/date/author paragraphs are non-normative metadata; all normative list paragraphs and table rows are mapped.

| Source blocks | Mapped REQs |
|---|---|
| Catalog P3-P14 | `REQ-MED-1B-CATALOG-001` through `REQ-MED-1B-CATALOG-003`; `REQ-MED-1B-COMMON-001` through `REQ-MED-1B-COMMON-017`; `REQ-MED-1B-SPECIAL-001` through `REQ-MED-1B-SPECIAL-005` |
| Catalog T15.2-T15.13 | `REQ-MED-1B-TYPE-001` through `REQ-MED-1B-TYPE-012` |
| Catalog P16-P17 and T18.2-T18.32 | `REQ-MED-1B-TEST-001` through `REQ-MED-1B-TEST-031` |
| Catalog P19-P20 and T21.2-T21.14 | `REQ-MED-CATALOG-OQ-001` through `REQ-MED-CATALOG-OQ-013` |
| Detailed Stage 1 TZ P43-P79 | `REQ-MED-1B-CATALOG-001` through `REQ-MED-1B-COUNTER-005`; `REQ-MED-1B-SPECIAL-001` through `REQ-MED-1B-SPECIAL-005`; test REQs 001-031; Stage 2 exclusion REQs in the Stage 1 file |
| Detailed Stage 1 TZ P80-P102 | `REQ-MED-1B-TAG-001` through `REQ-MED-1B-TAG-013` |
| Detailed Stage 1 TZ P103-P142 | `REQ-MED-1B-ROOT-001` through `REQ-MED-1B-ROOT-003`; `REQ-MED-1B-RESULT-001` through `REQ-MED-1B-RESULT-003`; `REQ-MED-1B-EFFECT-001`, `REQ-MED-1B-EFFECT-002`; `REQ-MED-1B-RECOMMEND-001`, `REQ-MED-1B-RECOMMEND-002` |

### Traceability result

- Source requirements mapped: `YES`.
- Detailed catalog blocks mapped: `P3-P20`, `T15.2-T15.13`, `T18.2-T18.32`, `T21.2-T21.14`.
- Catalog normative source blocks inventoried: `72`.
- Normative source blocks silently unmapped: `0`.
- Full source rows are not collapsed into a generic test-engine statement; every generic type and every test has a separate REQ-ID.
