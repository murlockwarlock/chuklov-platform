# Survey Builder Admin UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the existing Survey Definition editor human-manageable, fully editable for current platform scoring, fail-closed for unknown scoring, and add a derived human explanation to the existing referral configuration page.

**Architecture:** Keep `SurveyDefinition`/`SurveyVersion` canonical JSON, the existing mapper/compatibility/validator boundaries, and `UpdateSurveyDefinitionDraft`/`PublishSurveyVersion` as the only survey write authorities. Add a focused rich-scoring form projection and Filament presentation only; no new survey or referral subsystem is introduced. Referral copy is derived from `GetReferralRewardProgram` and existing currency configuration while `SaveReferralRewardProgram` remains the only write path.

**Tech Stack:** Laravel 13, Filament 5 schemas, Livewire page/resource tests, PHPUnit 12, Eloquent, existing survey and referral application services, PostgreSQL for version/provisioning verification.

**Spec:** `docs/superpowers/specs/2026-09-13-survey-builder-admin-ux-design.md`

## Global Constraints

- Published versions and historical attempts remain immutable.
- Current `PlatformSurveyCatalog` scoring fields round-trip without loss or semantic drift.
- Truly unsupported scoring remains preserved in `legacy_scoring` and is never silently rewritten.
- Organization context and authorization remain server-derived and are not accepted from form data.
- Existing survey save, stale-write, publish, provenance, and provisioning actions remain authoritative.
- No UUID, question key, metric key, JSON path, or internal enum is required in ordinary administrator-facing copy.
- No new dependency, migration, survey subsystem, scoring engine, clinical methodology, or referral economics is added.
- Only focused tests, syntax, Pint, diff checks, isolated PostgreSQL verification, and exact-SHA staging checks are required.

---

### Task 1: Establish rich scoring form contract

**Files:**
- Create: `app/Filament/Support/SurveyDefinitionScoringFormMapper.php`
- Modify: `app/Filament/Support/SurveyDefinitionFormMapper.php`
- Modify: `app/Filament/Support/SurveyDefinitionFormCompatibility.php`
- Modify: `app/Filament/Support/SurveyDefinitionFormOptions.php`
- Test: `tests/Unit/SurveyDefinitionFormMapperTest.php`

**Interfaces:**
- Consumes: canonical scoring arrays returned by `SurveyVersion` and the question/option identity maps already built by `SurveyDefinitionFormMapper`.
- Produces: `SurveyDefinitionScoringFormMapper::denormalize(array $scoring, array $questions): array`, `SurveyDefinitionScoringFormMapper::normalize(array $data): array`, and `SurveyDefinitionFormCompatibility::isHumanScoring(array $definition, array $scoring): bool` behavior that accepts exactly the current platform shape.

- [ ] **Step 1: Write failing mapper tests for the platform catalog shapes.**

Add focused PHPUnit tests that build both definitions from `PlatformSurveyCatalog::definitions()`, pass their version definition/scoring through the existing mapper, and assert that:

```php
$state = SurveyDefinitionFormMapper::denormalize($version);

expect($state['legacy_scoring'] ?? null)->toBeNull();
expect($state['answer_scale'])->not->toBeEmpty();
expect($state['metrics'][0]['max_value'])->toBe(20);
expect($state['metrics'][0]['question_keys'])->not->toBeEmpty();
expect($state['metrics'][0]['road_map'])->not->toBeEmpty();
expect($state['summary']['ru'])->not->toBeEmpty();
expect($state['safe_steps'])->not->toBeEmpty();
expect($state['specialist_questions'])->not->toBeEmpty();

$roundTripped = SurveyDefinitionFormMapper::normalize($state);

expect($roundTripped['scoring'])->toBe($version->scoring);
```

Use separate named tests for `platform_health_9_systems` and `platform_extended_symptoms`; do not assert only that a field exists.

- [ ] **Step 2: Run the new tests and verify the expected red failure.**

Run:

```bash
php artisan test --compact tests/Unit/SurveyDefinitionFormMapperTest.php --filter='platform.*scoring|rich.*scoring'
```

Expected result: failure because the current mapper marks the catalog scoring as legacy and does not expose rich fields.

- [ ] **Step 3: Implement the focused scoring projection.**

Move scoring-specific form conversion into `SurveyDefinitionScoringFormMapper`. Preserve the existing stable repeater identities and localized text representation. The form state must use:

```php
[
    'answer_scale' => [
        ['value' => 'never', 'label' => ['ru' => 'Никогда', 'en' => 'Never'], 'points' => 0],
    ],
    'metrics' => [
        [
            'key' => 'sleep',
            'label' => ['ru' => 'Сон и восстановление', 'en' => 'Sleep and recovery'],
            'max_value' => 20,
            'normalization' => 'symptom_burden_0_100',
            'question_keys' => ['stable-question-id'],
            'attention_reason' => ['ru' => '...', 'en' => '...'],
            'observation' => ['ru' => '...', 'en' => '...'],
            'road_map' => ['ru' => '...', 'en' => '...'],
        ],
    ],
    'rules' => [
        ['question_key' => 'stable-question-id', 'metric_key' => 'sleep', 'operator' => 'value_map', 'points' => [['value' => 'never', 'points' => 0]]],
    ],
    'thresholds' => [
        ['metric_key' => 'sleep', 'min' => 0, 'max' => 6, 'tag' => 'low', 'label' => ['ru' => '...', 'en' => '...']],
    ],
    'comparison' => ['operator' => 'no_decrease', 'metric_keys' => ['sleep'], 'basis' => 'normalized_score'],
    'summary' => ['ru' => '...', 'en' => '...'],
    'safe_steps' => [['ru' => '...', 'en' => '...']],
    'specialist_questions' => [['ru' => '...', 'en' => '...']],
]
```

Do not put technical identities in labels. Keep them as hidden mapper state needed for stable references. Preserve integer values as integers and decimal values as decimals so semantic equality and scoring behavior remain unchanged.

- [ ] **Step 4: Expand compatibility only for the known rich contract.**

Allow `answer_scale`, metric rich fields, `comparison.basis`, and result content with the exact current types. Continue returning `false` for unknown top-level keys, unknown nested fields, unsupported normalization values, malformed localized values, and unsupported operators. Keep the existing simple human scoring contract valid for imported/custom surveys.

- [ ] **Step 5: Run mapper and compatibility tests.**

Run:

```bash
php artisan test --compact tests/Unit/SurveyDefinitionFormMapperTest.php tests/Unit/SurveyDefinitionValidatorTest.php
```

Expected result: all existing tests and the new platform round-trip tests pass, including the existing unsupported-scoring preservation test.

### Task 2: Add rich scoring human validation and edit regressions

**Files:**
- Modify: `app/Modules/Surveys/Domain/Services/SurveyDefinitionValidator.php`
- Modify: `app/Filament/Support/SurveyDefinitionFormMapper.php`
- Test: `tests/Unit/SurveyDefinitionValidatorTest.php`
- Test: `tests/Unit/SurveyDefinitionFormMapperTest.php`

**Interfaces:**
- Consumes: normalized scoring from Task 1 and the existing canonical question/option structure.
- Produces: validation failures for missing metric rules, missing question/option references, invalid result ranges, overlapping or gapped integer ranges, and malformed rich result fields, with administrator-readable messages.

- [ ] **Step 1: Write failing validator and edit round-trip tests.**

Add tests with human-facing exception messages for:

```php
expect(fn () => $validator->validate($definition, $scoringWithoutMetricRules))
    ->toThrow(ValidationException::class, 'Показатель должен содержать хотя бы одно правило.');

expect(fn () => $validator->validate($definition, $scoringWithDeletedQuestion))
    ->toThrow(ValidationException::class, 'В подсчёте результата указана удалённая запись.');

expect(fn () => $validator->validate($definition, $scoringWithOverlappingThresholds))
    ->toThrow(ValidationException::class, 'Диапазоны результата пересекаются.');

expect(fn () => $validator->validate($definition, $scoringWithGap))
    ->toThrow(ValidationException::class, 'Между диапазонами результата есть пропуск.');
```

Add mapper tests that mutate exactly one answer point, one threshold label/range, `summary`, and an existing metric `road_map`, normalize, and assert only the intended canonical value changed while all other rich fields remain equal.

- [ ] **Step 2: Run the tests and verify each new test fails for the missing behavior.**

Run:

```bash
php artisan test --compact tests/Unit/SurveyDefinitionValidatorTest.php tests/Unit/SurveyDefinitionFormMapperTest.php --filter='metric.*rules|deleted|overlap|gap|point|threshold|road|summary'
```

- [ ] **Step 3: Implement conditional rich validation.**

Extend the existing validator without changing its public entry point. Validate rich optional fields only when present, keep canonical legacy/custom fields accepted where the existing contract permits them, and check references against the actual definition. Sort thresholds per metric by lower bound; reject `min > max`, duplicate threshold identity, overlap, and a gap for an integer score domain when the next lower bound is greater than the previous upper bound plus one. Keep unbounded thresholds valid. Use stable human messages and never include a UUID, key, JSON path, or raw enum in the message.

- [ ] **Step 4: Implement selective edit normalization.**

Ensure `normalize()` takes every rich form field back into the canonical scoring shape, including empty-versus-absent distinctions that are already meaningful in catalog data. Do not regenerate untouched content from labels or defaults. Preserve unsupported `legacy_scoring` byte/semantic-equivalent data through the existing bypass.

- [ ] **Step 5: Run all focused survey unit tests.**

Run:

```bash
php artisan test --compact tests/Unit/SurveyDefinitionFormMapperTest.php tests/Unit/SurveyDefinitionValidatorTest.php
```

### Task 3: Build the tabbed, collapsible Survey Definition editor

**Files:**
- Modify: `app/Filament/Resources/SurveyDefinitions/Schemas/SurveyDefinitionForm.php`
- Modify: `app/Filament/Resources/SurveyDefinitions/Pages/EditSurveyDefinition.php`
- Modify: `app/Filament/Resources/SurveyDefinitions/Pages/CreateSurveyDefinition.php` only if the shared schema requires a create-safe default
- Modify: `app/Filament/Support/SurveyDefinitionFormOptions.php`
- Test: `tests/Feature/SurveyDefinitionBuilderTest.php`
- Test: `tests/Feature/ContentSectionMediaTest.php` if its existing top-level schema assertion needs to follow the new Tabs container

**Interfaces:**
- Consumes: Task 1 form state and Task 2 validation; existing Filament schema and edit page actions.
- Produces: five human-named tabs, collapsed nested repeaters with human item summaries, rich scoring/result controls, derived preview, visible version status, and header save/publish actions that call the existing page lifecycle.

- [ ] **Step 1: Write failing schema and Livewire behavior tests.**

Assert the schema contains tabs named `Основное`, `Вопросы`, `Подсчёт результата`, `Результат для клиента`, and `Публикация / версия`; section/question repeaters expose human summaries and are collapsed; scoring controls do not expose internal keys as labels; the unknown warning is compact; and the page has header actions for saving the draft and publishing.

Add a focused state test that fills an existing platform version, changes a point and a threshold label, calls the page save action, reloads the draft, and asserts the values persist through `UpdateSurveyDefinitionDraft`.

- [ ] **Step 2: Run the new feature tests and verify the expected red failure.**

Run:

```bash
php artisan test --compact tests/Feature/SurveyDefinitionBuilderTest.php tests/Feature/ContentSectionMediaTest.php --filter='tabs|summary|collapsed|scoring|save|schema'
```

- [ ] **Step 3: Implement the tabbed schema using installed Filament components.**

Use `Tabs` and `Tabs\Tab` from the installed Filament schema API. Keep one `statePath('data')` and the existing field names consumed by the mapper. Set nested repeaters to collapsed defaults and use `itemLabel()` callbacks based on localized human text and question type. Keep new repeater items expanded with the existing default behavior. Preserve `reorderable()` and conditional option/condition behavior.

Place answer scale, metric metadata/rules, and comparison controls in the scoring tab. Place threshold result text and all client result content in the result tab. Render the unsupported notice only when `legacy_scoring` is present and do not render a disabled duplicate of the rich editor.

- [ ] **Step 4: Add human preview and version status placeholders.**

Build preview text from `Get` state: show current answer scale values/points and current threshold ranges/labels for the selected/current metric. Render the current published version and draft state from the form state supplied by `EditSurveyDefinition`; do not read or mutate persistence from the form schema. Keep internal source/methodology values hidden and present only a concise provenance/status description.

- [ ] **Step 5: Add header save action through the existing form lifecycle.**

Use the page’s existing `handleRecordUpdate` path for the new `saveDraft` header action. Do not call `UpdateSurveyDefinitionDraft` a second way from the schema. Keep publish explicit and use `PublishSurveyVersion` unchanged. Keep the new-scale confirmation copy short and explain that it is for a changed comparison principle.

- [ ] **Step 6: Run the focused Filament tests and the complete builder file.**

Run:

```bash
php artisan test --compact tests/Feature/SurveyDefinitionBuilderTest.php tests/Feature/ContentSectionMediaTest.php
```

### Task 4: Add referral human summary and calculation example

**Files:**
- Modify: `app/Modules/Referrals/Application/GetReferralRewardProgram.php`
- Modify: `app/Filament/Pages/ReferralRewardConfiguration.php`
- Modify: `resources/views/filament/pages/referral-reward-configuration.blade.php`
- Test: `tests/Feature/ReferralPartnerCrmUxTest.php`

**Interfaces:**
- Consumes: existing `GetReferralRewardProgram`, organization currency configuration, `CurrencyCatalog`, and current form state.
- Produces: derived summary fields for enabled state, qualification, reward formula/value, effective date, override precedence, and one example calculation; `SaveReferralRewardProgram` remains the only mutation authority.

- [ ] **Step 1: Write failing referral UX assertions.**

Extend the existing referral page feature test to enable percentage and fixed configurations and assert visible copy contains the current strategy and calculated example, for example:

```php
expect($html)->toContain('Если клиент оплатил 100 000');
expect($html)->toContain('партнёру будет начислено 10 000');
expect($html)->toContain('Индивидуальные условия имеют приоритет');
```

Use the configured organization currency and current saved amount/percentage in the test; do not assert a hardcoded business configuration.

- [ ] **Step 2: Run the referral test and verify it fails because the explanation is absent.**

Run:

```bash
php artisan test --compact tests/Feature/ReferralPartnerCrmUxTest.php --filter='summary|example|referral program'
```

- [ ] **Step 3: Add derived terms and example data.**

Extend the read model response with human labels and a deterministic example amount rendered in the organization’s configured base/display currency. Keep disabled programs explicit, use the existing fixed currency for fixed rewards, and calculate percentage examples with the existing money/rounding convention rather than a new formula. Expose effective/default/override terms without changing saved version records.

- [ ] **Step 4: Render the explanation above the existing form.**

Add a concise Filament/Blade block with “Как сейчас работает”, enabled/disabled state, qualification, reward, effective date, override precedence, and the current example. Keep the existing form controls and footer action unchanged.

- [ ] **Step 5: Run all focused referral tests.**

Run:

```bash
php artisan test --compact tests/Feature/ReferralPartnerCrmUxTest.php tests/Feature/ReferralRewardsTest.php
```

### Task 5: Lock version, attempt, and provisioning safety

**Files:**
- Modify: `tests/Feature/SurveyDefinitionBuilderTest.php`
- Modify: `tests/Feature/PlatformHealthExperienceTest.php`
- Modify: `tests/Feature/ReferralRewardsTest.php` only if the new derived read model needs a focused version-preservation assertion

**Interfaces:**
- Consumes: the existing application actions and the completed form/read-model behavior.
- Produces: regression evidence that edits create drafts, publishing preserves old attempts, and repeated provisioning does not overwrite admin-managed survey or referral state.

- [ ] **Step 1: Add failing history and provisioning regressions.**

Create a published attempt, edit/publish a new survey version, and assert the attempt’s `survey_version_id` and stored result remain unchanged. Mutate platform scoring, Road Map, summary, threshold text, and active pointer, run `InstallPlatformSurveyCatalog` twice, and assert all values remain unchanged. Mutate a referral reward version, run the existing provisioning/ensure paths used by the organization, and assert its version and economics remain unchanged.

- [ ] **Step 2: Run the focused regressions against the current test database.**

Run:

```bash
php artisan test --compact tests/Feature/SurveyDefinitionBuilderTest.php tests/Feature/PlatformHealthExperienceTest.php tests/Feature/ReferralRewardsTest.php --filter='attempt|history|provision|admin|version|preserv'
```

Expected result before any test-only setup fix: any failure must identify a missing assertion/setup problem, not be hidden by a broad suite.

- [ ] **Step 3: Keep the existing authorities unchanged while making tests pass.**

If a regression exposes mapper defaulting that would overwrite saved content, fix the mapper projection or provisioning predicate, not the immutable version or reward service. Do not add a second active pointer, duplicate save action, or direct table mutation.

- [ ] **Step 4: Run the complete focused survey/referral feature groups.**

Run:

```bash
php artisan test --compact tests/Feature/SurveyDefinitionBuilderTest.php tests/Feature/PlatformHealthExperienceTest.php tests/Feature/ReferralPartnerCrmUxTest.php tests/Feature/ReferralRewardsTest.php
```

### Task 6: Verify, commit, open Draft PR, and deploy exact SHA

**Files:**
- No product files beyond the changes above; include the approved spec and implementation plan in the first coherent branch commit if they are not already committed.

**Interfaces:**
- Consumes: all focused test evidence from Tasks 1–5 and the exact branch head.
- Produces: pushed branch, Draft PR, isolated PostgreSQL evidence, exact-SHA staging deployment/smoke result, and the requested concise final report.

- [ ] **Step 1: Inspect the final diff and run lightweight checks.**

Run:

```bash
git diff --check
git diff --stat
git status --short
for file in $(git diff --name-only -- '*.php'); do php -l "$file"; done
vendor/bin/pint --dirty
git diff --check
```

- [ ] **Step 2: Commit the first substantive implementation slice and open the Draft PR immediately.**

Before committing, inspect recent non-merge subjects and staged diff checks. Use the repository’s Russian infinitive style, for example:

```bash
git add docs/superpowers/specs/2026-09-13-survey-builder-admin-ux-design.md docs/superpowers/plans/2026-09-13-survey-builder-admin-ux.md app tests resources
git diff --cached --check
git diff --cached --stat
git commit -m "Сделать редактор опросов управляемым"
git push -u origin codex/survey-builder-admin-ux
gh pr create --draft --base main --head codex/survey-builder-admin-ux --title "Сделать редактор опросов управляемым" --body-file /tmp/chuklov-survey-builder-pr.md
```

The PR body must state the starting SHA, current scope, focused checks, PostgreSQL/staging status, and that merge/owner acceptance are pending. Do not include credentials or QA data.

- [ ] **Step 3: Run isolated PostgreSQL verification.**

Use the repository’s existing isolated PostgreSQL workflow and only the focused version/provisioning tests. Never point destructive test setup at staging:

```bash
docker-compose up -d postgres
make test-integration TESTS='tests/Feature/SurveyDefinitionBuilderTest.php tests/Feature/PlatformHealthExperienceTest.php'
```

Record `PASS` only when the command exits successfully; otherwise report `NOT RUN` or the exact failure classification.

- [ ] **Step 4: Push the final exact SHA and deploy to staging.**

After all code changes, push the final commit, resolve the staging target through the existing deployment helper, deploy the full SHA, and run the repository-owned staging smoke check. Never use destructive database commands against staging:

```bash
git push origin codex/survey-builder-admin-ux
STAGING_DEPLOY_REF=origin/codex/survey-builder-admin-ux make deploy-staging REVISION=$(git rev-parse HEAD)
./scripts/staging-smoke.sh
```

- [ ] **Step 5: Verify the exact acceptance path and report only executed evidence.**

Confirm staging is on `git rev-parse HEAD`, check health/smoke output, and report Browser as `NOT RUN` unless an available real browser completes the owner path. Owner acceptance remains `NOT RUN`; do not merge.
