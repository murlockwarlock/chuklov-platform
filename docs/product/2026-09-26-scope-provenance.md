# 26.09.2026 — scope provenance ledger

Starting implementation baseline: `c201b41a14d91c57c1890e62737f9e2001a231f1`. This ledger makes no pricing or legal conclusions. The baseline is current main, not a claim about deployed behavior on 26 September. Historical before/after dates remain NEEDS_REVIEW unless supported by dated commits/PR evidence.

Primary sources read directly: `11.08.26 ТЗ на CRM-бота v.2_2 detailed.docx`, `11.08.26 Лог изменений ТЗ changelog v2_2.docx`, and both original 26.09 lists. Private DOCX originals stay untracked. Accepted later architecture/owner decisions override contradictory technical sketches.

Dated implementation evidence recovered without broad history review: [PR #57](https://github.com/murlockwarlock/chuklov-platform/pull/57) Gift certificates merged 2026-10-09 11:23:27 UTC, merge `f40f22764d1c40c97f99daa7cc804b5c6421c577`; [PR #58](https://github.com/murlockwarlock/chuklov-platform/pull/58) Family and group booking merged 2026-10-09 17:35:58 UTC, merge `c201b41a14d91c57c1890e62737f9e2001a231f1`. These are post-26.09 code-delivery evidence for LIST 2 №7 and group booking; neither proves the complete family identity/delegation model or pre-26.09 implementation.

Family qualification: original v2.2 §9.2 says «Интерактивный таймлайн записей с перетаскиванием (drag-and-drop), цветными статусами, подсветкой долгов, учетом семейных/групповых визитов и переключением часового пояса страны нахождения Мастера». Thus general calendar consideration of family/group visits is ORIGINAL_SCOPE. Separate patient profiles, adult accounts, invitation binding, guardianship proof, medical delegation and revocation are not specified by that excerpt. CR-12 and LIST 2 №4 contain mixed ORIGINAL_SCOPE/SCOPE_EXPANSION/NEW_SCOPE subcriteria; the provisional whole-requirement NEW_SCOPE label below must not be read as denying the original calendar clause.

## CR-01 — Сокращение главного меню бота в чате Telegram

Current delivery reference for the implemented subset: [Draft PR #60](https://github.com/murlockwarlock/chuklov-platform/pull/60), first candidate `676c4da3436e30f0268aff2245db61958edb3a6f`. It adds managed entry videos/Stories, confirmed demographics/greeting, post-booking encrypted clinical editing, CRM chronic conditions, service medical flag and per-format buffers, pain visualization and the bonus header. Later fixes add client private uploads, mandatory fields, safe legacy display and overflow corrections. This dated record proves post-26.09 code delivery only for that subset, not complete implementation or staging/owner acceptance. Per-requirement delivery remains provisional until reconciled to final commit and exact-SHA evidence.

26.09 source: LIST 1 §5 CR-01; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#cr-01).

Classification: **SUPERSEDED** (provisional). Original multi-button menu and welcome media; new single-button entry supersedes menu composition.
Original v2.2 source: §4.1.
Original excerpt: «Кнопка «Об авторе»: Выдача биографии Евгения Чуклова, регалий, дипломов, клинического опыта, видеоприветствия и ссылок на соцсети.».

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: MEDIUM — source clause exists; detailed subcriteria may have different classification.

## CR-02 — Горизонтальный Stories-компонент на Главной с точными видео Евгения

26.09 source: LIST 1 §5 CR-02; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#cr-02).

Classification: **SCOPE_EXPANSION** (provisional). Source names author/method content, not four Stories or fullscreen gestures.
Original v2.2 source: §4.1.
Original excerpt: «Кнопка «О методе»: Презентация авторской кинезиологической и остеопатической методики.».

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: MEDIUM — source clause exists; detailed subcriteria may have different classification.

## CR-03 — Fallback-логика приветствия в шапке (Header Greeting)

26.09 source: LIST 1 §5 CR-03; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#cr-03).

Classification: **NEW_SCOPE** (provisional). Confirmed-name greeting and exact fallback absent as an explicit original criterion.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.

## CR-04 — Smart Empty State для виджета бронирования

26.09 source: LIST 1 §5 CR-04; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#cr-04).

Classification: **NEW_SCOPE** (provisional). Exact empty-state composition/copy is new; ordinary booking remains original scope.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.

## CR-05 — Редизайн и динамика виджета Health Action

26.09 source: LIST 1 §5 CR-05; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#cr-05).

Classification: **SCOPE_EXPANSION** (provisional). Tracker and Road Map exist in original scope; three exact Home states are later expansion.
Original v2.2 source: §5.1.
Original excerpt: «Функционал: Бот ежедневно/еженедельно запрашивает отчёты о выполнении пунктов «Дорожной карты» (упражнения, питьевой режим, выгрузки, приём комплексов).».

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: MEDIUM — source clause exists; detailed subcriteria may have different classification.

## CR-06 — Редизайн кнопки коммуникации (Чат)

26.09 source: LIST 1 §5 CR-06; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#cr-06).

Classification: **CLARIFICATION** (provisional). AI interaction existed; exact CTA/tab copy is later clarification.
Original v2.2 source: §1.1.
Original excerpt: «Создание международной автономной цифровой экосистемы на домене [www.chuklov.com](https://www.chuklov.com), объединяющей клиентский интерфейс записи, диагностики и воронки продаж (Telegram Mini App + AI-Бот) и автоматизированное рабочее место терапевта (Web Admin Panel).».

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: MEDIUM — source clause exists; detailed subcriteria may have different classification.

## CR-07 — Актуализация полей профиля в «Личных данных» (Mini App и CRM)

26.09 source: LIST 1 §5 CR-07; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#cr-07).

Classification: **SCOPE_EXPANSION** (provisional). Original names/contact/country/city/birth date; middle name, gender, exact controls and automatic username change are later details.
Original v2.2 source: §4.2.
Original excerpt: «Экран 1: Контакты и Источник (Имя, Фамилия, TG Ник, Телефон, Страна, Город, Дата рождения + Поле источника, если нет Deep Link).».

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: MEDIUM — source clause exists; detailed subcriteria may have different classification.

## CR-08 — Кнопка «Медицинские данные» в Кабинете и логика визарда после записи

26.09 source: LIST 1 §5 CR-08; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#cr-08).

Classification: **SUPERSEDED** (provisional). Prefill is original; post-booking timing supersedes original questionnaire-before-service sequence.
Original v2.2 source: §4.2.
Original excerpt: «4.2. Onboarding & Progressive Profiling (Wizard на 4 экрана)».

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: MEDIUM — source clause exists; detailed subcriteria may have different classification.

## CR-09 — Синхронизация полей в карточке клиента в Web-CRM

26.09 source: LIST 1 §5 CR-09; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#cr-09).

Classification: **SCOPE_EXPANSION** (provisional). Clinical history is original; exact split/display and demographic expansion are later details.
Original v2.2 source: §4.3.
Original excerpt: «Вкладка «Анамнез»: История хронических состояний, детали прошлых операций и травм, а также список принимаемых лекарств и БАДов.».

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: MEDIUM — source clause exists; detailed subcriteria may have different classification.

## CR-10 — Разграничение прав доступа к медицинской карте (Клиент vs Мастер)

26.09 source: LIST 1 §5 CR-10; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#cr-10).

Classification: **CLARIFICATION** (provisional). Original confidentiality and cockpit; precise client/private field allowlist added later.
Original v2.2 source: §1.4.
Original excerpt: «Шифрование персональных данных: Использование встроенного механизма шифрования Laravel (encrypt / decrypt) для полей ФИО, медицинских жалоб, кинезиологических тестов и контактов.».

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: MEDIUM — source clause exists; detailed subcriteria may have different classification.

## CR-11 — Системный флаг услуги requires_medical_profile (Каталог Услуг)

26.09 source: LIST 1 §5 CR-11; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#cr-11).

Classification: **NEW_SCOPE** (provisional). Explicit per-service requires_medical_profile flag is not an original source field.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.

## CR-12 — Архитектурная поддержка семейной и групповой записи

26.09 source: LIST 1 §5 CR-12; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#cr-12).

Classification: **NEW_SCOPE** (provisional). Separate family identity/delegation/invitation is not group_size.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.

## CR-13 — Каскад напоминаний о заполнении карты здоровья (Notification Engine)

26.09 source: LIST 1 §5 CR-13; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#cr-13).

Classification: **SCOPE_EXPANSION** (provisional). Original visit reminders differ from health-card completeness cascade; exact 0/24h/2h clinical reminders added later.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.

## L2-01 — Поле «Примечание» в карте клиента

26.09 source: LIST 2 №1; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-01).

Classification: **NEEDS_REVIEW** (provisional). LIST 2 marks 11.08; date alone is not proof of inclusion in v2.2.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.

## L2-02 — Чёрный список (раздел в клиентах)

26.09 source: LIST 2 №2; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-02).

Classification: **NEEDS_REVIEW** (provisional). LIST 2 marks 11.08; exact blacklist/notes original clause needs corroboration.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.

## L2-03 — Быстрая запись на несколько сеансов

26.09 source: LIST 2 №3; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-03).

Classification: **NEW_SCOPE** (provisional). Client multi-slot basket before medical data is distinct from single booking/group_size.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: client selection/add/remove and atomic, replay-safe submission implemented in `ed0cc38`; existing CreateMultipleBookings/CreateBooking extended without a second engine. PostgreSQL batch-race/foundation gates PASS; browser found an incorrect medical-return destination, being corrected. Full staging lifecycle remains NOT VERIFIED.
PR/commit/evidence: [Draft PR #60](https://github.com/murlockwarlock/chuklov-platform/pull/60), `ed0cc38`, [PostgreSQL concurrency](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38411051526), exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.

## L2-04 — Запись нескольких членов семьи одним пользователем

26.09 source: LIST 2 №4; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-04).

Classification: **NEW_SCOPE** (provisional). Explicit family linking/legal questions require owner decision.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.

## L2-05 — Визуализация шкалы боли

26.09 source: LIST 2 №5; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-05).

Classification: **SCOPE_EXPANSION** (provisional). Pain tracking is original; interactive face/color progression added later.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.

## L2-06 — Работа с бонусами

26.09 source: LIST 2 №6; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-06).

Classification: **SCOPE_EXPANSION** (provisional). Original cabinet bonus balance; top-right presentation and display/spend conversion detail added later; retain accepted finance decisions.
Original v2.2 source: §2.3.
Original excerpt: «Начисление бонусов: Автоматический учёт переходов и регистраций. Накопленный баланс бонусов отображается в Личном Кабинете клиента.».

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: MEDIUM — source clause exists; detailed subcriteria may have different classification.

## L2-07 — Работа с сертификатами:

26.09 source: LIST 2 №7; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-07).

Classification: **NEW_SCOPE** (provisional). Gift certificate issue/claim/transfer lifecycle not established by original product sales.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.

## L2-08 — Статистика по рекомендациям, чтобы оценить эффективность в целом и конкретными пользователями

26.09 source: LIST 2 №8; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-08).

Classification: **ORIGINAL_SCOPE** (provisional). Transition and registration analytics explicitly original; reconcile accepted partner-only changes.
Original v2.2 source: §2.3.
Original excerpt: «Начисление бонусов: Автоматический учёт переходов и регистраций. Накопленный баланс бонусов отображается в Личном Кабинете клиента.».

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: MEDIUM — source clause exists; detailed subcriteria may have different classification.

## L2-09 — При создании новой записи на приём

26.09 source: LIST 2 №9; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-09).

Classification: **CLARIFICATION** (provisional). Red backdating warning is later CRM presentation requirement.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.

## L2-10 — Добавить никнейм телеграм в карточке клиента (сейчас есть только telegram id) со ссылкой на тг акк по id или никнейму.

26.09 source: LIST 2 №10; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-10).

Classification: **CLARIFICATION** (provisional). Original Telegram nickname; exact CRM link/presentation clarified later.
Original v2.2 source: §4.2.
Original excerpt: «Экран 1: Контакты и Источник (Имя, Фамилия, TG Ник, Телефон, Страна, Город, Дата рождения + Поле источника, если нет Deep Link).».

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: MEDIUM — source clause exists; detailed subcriteria may have different classification.

## L2-11 — Переименовать везде кнопки с «Создать» и «Создать и создать ещё» в «Сохранить»и «Сохранить и создать ещё»

26.09 source: LIST 2 №11; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-11).

Classification: **CLARIFICATION** (provisional). Save labels are later UI convention.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.

## L2-12 — В каждой услуге добавить «время перерыва после записи». Для каждого формата услуги этот период может быть свой.

26.09 source: LIST 2 №12; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-12).

Classification: **SCOPE_EXPANSION** (provisional). Original technical buffer; separate per-format values are expansion.
Original v2.2 source: §3.2.
Original excerpt: «Хронометраж и Buffer Time: Чистое время сеанса + Технический перерыв (15–30 минут), автоматически блокируемый в расписании после приёма.».

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: MEDIUM — source clause exists; detailed subcriteria may have different classification.

## L2-13 — «Адрес по умолчанию» в настройках расписания \- переименовать в «Адрес приёма по умолчанию»

26.09 source: LIST 2 №13; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-13).

Classification: **CLARIFICATION** (provisional). Default address label refinement.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.

## L2-14 — Настройки расписания

26.09 source: LIST 2 №14; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-14).

Classification: **SCOPE_EXPANSION** (provisional). Scheduling exists; exact page grouping/navigation, multiselect and merged unavailable periods are later restructuring.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.

## L2-15 — В карточке «Медицинский профиль» пользователя разделить поля:

26.09 source: LIST 2 №15; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-15).

Classification: **CLARIFICATION** (provisional). Separate original clinical concepts; later exact independent controls.
Original v2.2 source: §4.2.
Original excerpt: «Экран 2: Клинический профиль (Мультиселект жалоб, операции, травмы, вопрос о квалификации массажиста).».

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: MEDIUM — source clause exists; detailed subcriteria may have different classification.

## L2-16 — Редактировать шаблон сообщения для рассылки. Не закрепляется фото/видео в сообщении

26.09 source: LIST 2 №16; exact complete criteria: [acceptance matrix](../verification/2026-09-26-requirements-acceptance.md#l2-16).

Classification: **BUG_FIX** (provisional). Media failing to persist is reported defect; original media attachment/template commitment needs exact corroboration.
Original v2.2 source: exact clause not established.
No original clause is inferred from a generic module name or document date.

Before 26.09: chronology NOT ESTABLISHED; starting-main implementation is recorded in the acceptance matrix.
After 26.09: task branch changes/evidence pending; do not infer code delivery from a requirement or proof declaration.
PR/commit/evidence: starting SHA above, task PR pending, exact-SHA staging NOT RUN.
Limitations: see individual source criteria; family legal authority/media/methodology/provider evidence remain unresolved where applicable.
Confidence: LOW — precise original clause/chronology needs review.
