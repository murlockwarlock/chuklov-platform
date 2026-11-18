# Design: графики динамики медицинских сеансов

## Intent

Дать специалисту компактное фактическое представление сохранённых изменений по текущему и до девяти предыдущих медицинских сеансов в существующем разделе «Динамика подтверждённых фактов».

## Goals

- Переиспользовать `GetSessionDynamics` и `SessionDynamicsData` как единственный источник истории.
- Визуально разделить серии «До» и «После» для сохранённых vitals, боли и сопоставимых результатов одинакового теста.
- Сохранить порядок по датам в timezone организации, пропуски как пропуски, а ноль как сохранённое значение.
- Сделать экран понятным при 0/1/5/10 исторических точках и без горизонтального overflow на заданных ширинах.
- Оставить существующую текстовую фактическую историю доступной для точных значений и источника записи.

## Non-goals

- Нет клинической интерпретации, направления изменения, процентов эффективности, индексов, порогов, диагнозов или рекомендаций.
- Нет новой таблицы, workflow, storage, AI-запроса или параллельного источника истории.
- Нет изменения сохранённых медицинских данных, каталога тестов или клиентской публикации.

## Constraints

- История ограничена текущим сеансом и девятью предыдущими, с авторизацией `GetSessionDynamics`.
- Сравнение тестов допускается только при совпадении сохранённого идентификатора определения теста и совместимого структурированного типа/версии; при отсутствии доказательства совместимости тестовая серия не соединяется.
- В график попадают только числовые сохранённые значения. `null` не преобразуется в ноль.
- Для vitals используются существующие ключи и единицы: SYS/DIA, пульс, индекс перфузии и SpO2.

## Approaches

1. Server-rendered SVG projection in the existing `SessionDynamicsData`, rendered by a `ViewEntry`. Chosen because it keeps authorization and history in the existing Application path, works without a new frontend dependency, and scales to ten points in a responsive viewBox.
2. A separate JavaScript chart component and chart dependency. Rejected because it duplicates the presentation boundary and requires a frontend build for a bounded CRM infolist change.
3. A second comparison table. Rejected because it duplicates the current factual history and does not provide the requested visual change.

## Chosen design

Add a read-only chart payload method to `SessionDynamicsData`. The payload reverses the existing current-first history for chronological display, carries organization-timezone date labels, and exposes only compatible metric series. The existing section renders one responsive SVG at a time with accessible metric buttons and distinct «До»/«После» legend keys. The existing factual entries remain the exact-value source and retain their current authorization path.

Update REQ-MED-2-AI-011 and REQ-MED-2-AI-012 only to `PARTIALLY IMPLEMENTED` for factual display; pattern interpretation and clinical trend analysis remain deferred.

## Verification boundary

Focused regression tests cover 0/1/5/10 history points, chronological order, null versus zero, before/after separation, compatible test identity, and tenant authorization. Staging acceptance uses the exact deployed SHA with PostgreSQL and a normal QA auth-preflight, followed by one Chromium and one WebKit journey at the requested viewport widths, including reload and back/forward.
