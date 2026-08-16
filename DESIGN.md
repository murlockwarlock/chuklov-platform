# Chuklov Platform — Visual Design System & UI Contract

> **Document Status**: DESIGN FREEZE CANDIDATE (Milestone 10 AI UI Baseline)  
> **Target Audience**: Full-Stack Engineers, AI Agents, UI Specialists  
> **Reference Screens (Stitch Project `10400861802131455607`)**:
> - Desktop Reference: `958dc4b3f4244f3cbfc981352b344ed2` (*Client CRM - Final Refined Architecture*)
> - Mobile Reference: `899e180315ed4d13aa0851d35bcfd51d` (*Client CRM View - Mobile Responsive*)

---

## 1. Product Character & Aesthetic Philosophy

Chuklov is an authoritative, high-precision clinical and rehabilitation platform for medical specialists.

### Core Character Directives
- **Clinical & Authoritative**: The interface evokes the quiet precision of high-end diagnostic tools and Swiss typography. It treats medical and patient data with absolute seriousness.
- **Information-Dense, Never Cluttered**: Specialists need high scanability without excessive whitespace or cartoonish padding. Data is organized in structured grids and hairline-divided tabular sections.
- **Restrained & Dignified**: Premium feel is achieved through typographic discipline, calibrated neutrals, and crisp 1px borders — **not** through luxury flourishes, shadows, or decorative noise.
- **Human & Calm**: The palette uses neutral slate and charcoal tones with warm amber/bronze functional accents, avoiding both cold hospital blues and flashy SaaS neons.
- **Zero AI-Slop**: Every UI element must represent real clinical or operational state. No decorative gradients, glowing pills, floating blobs, or marketing landing-page patterns inside the CRM.

---

## 2. Color System & Semantic Roles

The color palette is strictly calibrated for sustained daily clinical work under high-density data conditions.

### 2.1 Neutral Bases & Surfaces
| Token | Hex | Tailwind / CSS Equivalent | Functional Role |
| :--- | :--- | :--- | :--- |
| `surface-canvas` | `#F8FAFC` | `bg-slate-50` | Primary application canvas / viewport background |
| `surface-panel` | `#FFFFFF` | `bg-white` | Main workspace cards, data tables, and modal backgrounds |
| `surface-subtle` | `#F1F5F9` | `bg-slate-100` | Table headers, secondary left rail, metadata boxes |
| `surface-sidebar` | `#0F172A` | `bg-slate-900` | Global persistent navigation sidebar |
| `surface-sidebar-hover`| `#1E293B` | `bg-slate-800` | Hover / active state background in dark sidebar |

### 2.2 Borders & Dividers
| Token | Hex | Tailwind / CSS Equivalent | Functional Role |
| :--- | :--- | :--- | :--- |
| `border-hairline` | `#E2E8F0` | `border-slate-200` | 1px hairline dividers between content blocks and table rows |
| `border-subtle` | `#CBD5E1` | `border-slate-300` | Input borders, active tab underlines, component boundaries |
| `border-sidebar` | `#1E293B` | `border-slate-800` | Subtle section divider inside dark sidebar |

### 2.3 Typography & Text Roles
| Token | Hex | Tailwind / CSS Equivalent | Functional Role |
| :--- | :--- | :--- | :--- |
| `text-primary` | `#0F172A` | `text-slate-900` | Primary data values, headers, patient names, diagnoses |
| `text-secondary` | `#475569` | `text-slate-600` | Clinical notes, paragraph anamnesis, body copy |
| `text-muted` | `#64748B` | `text-slate-500` | Micro-labels, metadata captions, timestamps, column headers |
| `text-inverse` | `#F8FAFC` | `text-slate-50` | Text on dark surfaces (sidebar, primary buttons) |

### 2.4 Brand & Functional Accents
| Token | Hex | Tailwind / CSS Equivalent | Functional Role |
| :--- | :--- | :--- | :--- |
| `accent-primary` | `#D97706` | `bg-amber-600` / `text-amber-600` | Active navigation indicator, primary brand touchpoint |
| `accent-subtle` | `#FEF3C7` | `bg-amber-50` | Active selection tint, highlighted table row background |

### 2.5 Clinical & Operational Semantic States
| Semantic State | Background | Border | Text | Usage Context |
| :--- | :--- | :--- | :--- | :--- |
| **Confirmed Fact / Success** | `#F0FDF4` (`emerald-50`) | `#BBF7D0` (`emerald-200`) | `#166534` (`emerald-800`) | Verified channel, clean file scan, completed session, active patient |
| **Specialist Hypothesis** | `#FFFBEB` (`amber-50`) | `#FDE68A` (`amber-200`) | `#92400E` (`amber-800`) | Working clinical hypothesis, provisional etiology (must remain distinct from fact) |
| **Warning / Attention** | `#FFFBEB` (`amber-50`) | `#FCD34D` (`amber-300`) | `#B45309` (`amber-700`) | Unverified channel, booking restriction active, pending review |
| **Critical / Error** | `#FEF2F2` (`rose-50`) | `#FECACA` (`rose-200`) | `#991B1B` (`rose-800`) | Quarantined file, payment dispute, failed verification |
| **Protected / Encrypted** | `#F8FAFC` (`slate-50`) | `#E2E8F0` (`slate-200`) | `#334155` (`slate-700`) | Class C encrypted medical data container indicator |

---

## 3. Typography Architecture

### 3.1 Font Stack
- **Primary Interface Font**: `Inter`, `Geist Sans`, or system `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif`. Clean grotesque sans-serif with high x-height and exceptional legibility at 11–13px.
- **Monospace Font**: `JetBrains Mono`, `Geist Mono`, or `ui-monospace, SFMono-Regular, Menlo, monospace`. Strictly reserved for operational identifiers, phone numbers, referral codes, timestamps, and cryptographic hashes.

### 3.2 Typographic Hierarchy
| Hierarchy Level | Size | Weight | Line Height | Tracking | Application |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Page / Client Name** | 22px (`1.375rem`) | 600 (SemiBold) | 28px (`1.75rem`) | `-0.015em` | Client full name, main page title |
| **Section Header** | 15px (`0.9375rem`) | 600 (SemiBold) | 22px (`1.375rem`) | `-0.01em` | Major workspace section titles |
| **Sub-Header / Card Title** | 13px (`0.8125rem`) | 600 (SemiBold) | 18px (`1.125rem`) | Normal | Subsection headers, table headers |
| **Data Label (Micro-label)**| 11px (`0.6875rem`) | 500 (Medium) | 14px (`0.875rem`) | `+0.04em` | Uppercase field descriptors (`ТЕЛЕФОН`, `АНАМНЕЗ`) |
| **Primary Value / Body** | 13px (`0.8125rem`) | 400–500 | 20px (`1.25rem`) | Normal | Patient field values, table cell text, dialog copy |
| **Clinical Long-Form** | 13px (`0.8125rem`) | 400 (Regular) | 22px (`1.375rem`) | Normal | Anamnesis notes, complaints, specialist protocols |
| **Data Monospace** | 12px (`0.75rem`) | 400–500 | 16px (`1.0rem`) | Normal | Phone numbers, IDs (`#104`), codes (`REF-8841`), timezones |

---

## 4. Spacing, Sizing & Layout Density

### 4.1 Density Philosophy: "Clinical Cockpit"
The interface avoids the cavernous whitespace of marketing websites. Information is densely packed but cleanly separated through 1px borders and distinct typographic weights.

### 4.2 Spacing Constants
- **Base Grid**: 4px (`0.25rem`)
- **Micro-Gaps (Between Label & Value)**: 4px–6px
- **Field-to-Field Gap (Vertical)**: 12px–16px
- **Section-to-Section Gap**: 20px–24px
- **Left Rail Width**: Fixed `320px` on desktop (collapses to full width on mobile)
- **Global Sidebar Width**: Fixed `240px` (desktop), `64px` (collapsed/tablet), `0px` (hidden drawer on mobile)
- **Top Bar Height**: `52px` (compact desktop/tablet), `48px` (mobile)
- **Max Content Container Width**: `1440px` (centered or left-anchored in large viewports)

---

## 5. Layout Architecture (The Asymmetric Client Workstation)

```
+----------------------------------------------------------------------------------------------------------+
| 1. GLOBAL TOP BAR (52px): Breadcrumbs | Org Context (#1) | Search (⌘K) | User Profile (Д-р Солдатов)    |
+-------------------+--------------------------------------------------------------------------------------+
| 2. PERSISTENT     | 3. CLIENT HEADER (Compact high-value state only):                                    |
|    SIDEBAR        |    Иван Иванович Иванов  [● Активен]  [#104]                                         |
|    (240px)        |    Действия: [ + Новый сеанс ]  [ Редактировать профиль ]  [ Действия ▾ ]            |
|                   +--------------------------------------------------------------------------------------+
| • Клиенты (act)   | 4. SUB-NAVIGATION TABS (2px bottom border active):                                   |
| • Записи          |    [ Клинический профиль ]  [ Сеансы (4) ]  [ Записи (6) ]  [ Опросы (2) ]  [ Файлы ]|
| • Команда/Услуги  +---------------------------------------+----------------------------------------------+
| • Коммуникации    | 5. LEFT RAIL (320px):                 | 6. RIGHT MAIN WORKSPACE (~880px):            |
| • База знаний     | • Контакты и телефон (mono)           | • Клинический анамнез (Class C Encrypted)    |
| • Финансы         | • Telegram / Email (верификация)      | • Жалобы, ВАШ и цели восстановления          |
| • Безопасность    | • Часовой пояс и язык                 | • Фармакотерапия и нутрицевтики              |
|                   | • Ограничения самостоятельной записи  | • Последний сеанс (протокол + гипотеза)      |
|                   | • Юридические согласия (ПДн, мед)     | • Таблица медицинских документов и МРТ       |
|                   | • Финансовый баланс (0 ₽)             |                                              |
+-------------------+---------------------------------------+----------------------------------------------+
```

### 5.1 Sidebar (Global Navigation)
- Dark Slate (`#0F172A`) container with crisp white typography.
- Uses official Chuklov module groups: `Клиенты`, `Записи`, `Команда и услуги`, `Коммуникации`, `Контент и знания`, `Финансы`, `Безопасность`.
- Active item uses subtle Amber left border indicator and Slate-800 background tint.

### 5.2 Top Bar
- Height: `52px`.
- Contains: minimal breadcrumb path (`Клиенты / Карточка #104`), server-resolved Organization badge (`Организация #1`), global search trigger (`⌘K`), and specialist profile.

### 5.3 Client Header Bar
- **Strict Rule**: Keep header clean and focused. Only high-value primary identity is permitted here:
  - Patient Full Name (`22px SemiBold`);
  - Primary Clinical Status (`Активен` with 6px emerald dot);
  - Patient Record ID (`#104`).
- **Forbidden in Header**: Timezone, Telegram verification, referral codes, legal consents, and phone numbers must NOT clutter the header. They belong in the Left Rail.
- **Action Buttons**:
  - Primary solid button: `+ Новый сеанс` (`bg-slate-900 text-white hover:bg-slate-800`, 4px radius);
  - Secondary outline button: `Редактировать профиль`;
  - Tertiary ghost menu: `Действия ▾` (Блокировка записи, Экспорт, Аудит).

### 5.4 Context Sub-Navigation Tabs
- Strict set of top-level client tabs:
  1. `Клинический профиль` (Active overview dossier)
  2. `Сеансы (4)` (Full clinical history, dynamics comparison)
  3. `Записи на приём (6)` (Past and upcoming bookings)
  4. `Опросы (2)` (Survey attempts and score trends)
  5. `Файлы и МРТ (2)` (All uploaded medical attachments)
- **IA Constraint**: Do NOT turn secondary operational details (finances, consents, channel identities) into standalone top-level tabs.

### 5.5 Asymmetric Workspace Split
- **Left Rail (`320px`)**: Operational and demographic matrix (telephone, email, Telegram identity, timezone, language, referral code, consent versions, booking restrictions, balance summary).
- **Right Main Area (`~880px`)**: Clinical workspace (anamnesis, complaints, drug regimen, session dynamics, and verified attachment tables).

---

## 6. Component Guidelines

### 6.1 Buttons
- **Shape**: Crisp 4px–6px radius (`rounded-[4px]`). Never pill-shaped (`rounded-full` is banned for CRM action buttons).
- **Height**: `36px` on Desktop, `44px` on Mobile touchscreens.
- **Variants**:
  - `Primary`: `bg-slate-900 text-white hover:bg-slate-800 active:bg-slate-950`
  - `Secondary / Outlined`: `border border-slate-300 text-slate-700 bg-white hover:bg-slate-50`
  - `Ghost`: `text-slate-600 hover:bg-slate-100 hover:text-slate-900`
  - `Destructive`: `border border-rose-200 text-rose-700 bg-rose-50 hover:bg-rose-100`

### 6.2 Data Tables (Attachments, Sessions, Obligations)
- **Table Structure**: Full-width bordered container with `border-t` and `border-b` dividers (`#E2E8F0`).
- **Header**: `bg-slate-50`, `text-[11px] uppercase tracking-wider text-slate-500 font-medium`, `py-2.5 px-3`.
- **Row**: `py-2.5 px-3`, `text-[13px] text-slate-800`, hover state `bg-slate-50/70`.
- **Dividers**: Clean 1px single hairline (`border-b border-slate-200`). No vertical gridlines unless displaying multi-column financial ledgers.

### 6.3 Clinical Data Blocks & Cards
- **Container Structure**: Structured white panels (`bg-white`) bordered by 1px slate-200 (`border border-slate-200 rounded-[6px]`).
- **No Floating Card Bubble Slop**: Do not put every individual string into a separate floating card. Group related fields into a single unified bordered panel with clear internal divider lines.
- **Section Headers**: Compact with 1px bottom border: `border-b border-slate-100 pb-2 mb-3`.

### 6.4 Status Badges & Pills
- **Constraint**: Use sparingly. Badges must only convey real operational/clinical state.
- **Style**: Small height (`20px`), compact horizontal padding (`px-2`), subtle border, 4px radius, `text-[11px] font-medium`.
- **Colors**: Strictly adhere to the semantic states defined in Section 2.5.

---

## 7. Clinical Semantics & Information Integrity

A fundamental invariant of the Chuklov platform is maintaining absolute clarity regarding the epistemic status of clinical records.

```
+--------------------------------------------------------------------------------------------------+
| CLINICAL RECORD HIERARCHY                                                                        |
|                                                                                                  |
| [1. RECORDED SPECIALIST FACTS]      Pain, Tests, Observations, Protocol, Direct Result           |
|                                     -> Solid factual presentation, authoritative neutral slate   |
|                                                                                                  |
| [2. SPECIALIST HYPOTHESIS]          root_cause_hypothesis                                        |
|                                     -> Explicitly styled in Amber hypothesis box with disclaimer |
|                                     -> "Рабочая гипотеза специалиста (требует подтверждения)"    |
|                                                                                                  |
| [3. CLIENT-REPORTED DATA]           Intake complaints, subjective VAS rating, patient goals      |
|                                     -> Labeled as client subjective report                       |
|                                                                                                  |
| [4. FUTURE AI PROPOSALS (M10)]      AI draft summary, pattern detection, suggested protocols     |
|                                     -> Strict AI badge, non-blocking, specialist confirmation req |
+--------------------------------------------------------------------------------------------------+
```

### 7.1 Separation of Facts from Hypotheses
1. **Confirmed / Recorded Specialist Facts**:
   - Fields: `pain`, `tests`, `observations`, `protocol`, `result`.
   - Visual Treatment: Standard crisp neutral presentation. Treated as confirmed clinical records authored by the treating specialist.
2. **Specialist Hypothesis (`root_cause_hypothesis`)**:
   - Invariant: `root_cause_hypothesis` is **NEVER** presented as an established medical fact or verified diagnosis.
   - Visual Treatment: Encapsulated in a dedicated Specialist Hypothesis block (`bg-amber-50/50 border border-amber-200/80 rounded-[4px] p-3`).
   - Prefix / Title: `Гипотеза о первопричине (рабочая версия специалиста)`.
3. **Future M10 AI Content**:
   - Must be distinctly demarcated with a clear provider-agnostic AI proposal tag and never automatically overwrite specialist facts.

---

## 8. Borders, Radius & Shadows (Strict Constraints)

- **Border Radius Rules**:
  - Buttons / Inputs / Action Triggers: `4px` (`rounded-[4px]`) or max `6px`.
  - Content Panels & Tables: `4px`–`6px` (`rounded-[6px]`).
  - Modal Dialogs: `8px` (`rounded-lg`).
  - **Banned**: `12px`, `16px`, `24px` or `rounded-full` pills on primary CRM cards.
- **Border Thickness**:
  - Always exactly `1px` solid (`border border-slate-200`). Never 2px or 3px decorative borders.
- **Shadow Rules**:
  - Standard Panels: `shadow-none` or ultra-subtle `shadow-[0_1px_2px_0_rgba(0,0,0,0.03)]`.
  - Dropdowns & Popovers: `shadow-md border border-slate-200`.
  - **Banned**: Heavy floating drop-shadows (`shadow-xl`, `shadow-2xl`), colored neon glows, and colored shadow halos.

---

## 9. Responsive & Mobile Viewport Standards

### 9.1 Viewport Breakpoints
- **Desktop (`>= 1280px`)**: Full asymmetric 2-column layout + 240px persistent sidebar.
- **Tablet / Laptop (`768px – 1279px`)**: Sidebar collapses to icon rail or slide-over drawer; Left rail and main workspace stack gracefully (Left rail `280px` or full width top stack).
- **Mobile (`< 768px`)**: Single-column vertical flow with sticky mobile header.

### 9.2 Mobile Invariants
1. **No Sidebar Offset**: On mobile, the desktop sidebar offset is completely removed (`left-0`, `pl-0`). The workspace spans the full 100% viewport width.
2. **Touch Targets (`>= 44px`)**: All mobile action buttons, tab triggers, and links must have a minimum clickable/tap height of `44px`.
3. **No Hover Dependencies**: Actions (e.g. download link, edit button, menu toggle) must be directly visible and accessible without requiring hover.
4. **Horizontal Tab Navigation**: Client tabs scroll horizontally without breaking into awkward multi-line stacks (`overflow-x-auto whitespace-nowrap no-scrollbar`).
5. **Zero Horizontal Page Overflow**: Tables and wide clinical entries must wrap or use dedicated horizontal scrolling containers with clear scroll affordance.

---

## 10. Explicit Prohibitions (Anti-AI-Slop Directives)

The following patterns are **strictly prohibited** across all present and future Chuklov interfaces:

- ❌ **NO Purple / Indigo / Violet Glowing Gradients**: Do not use "AI SaaS" aesthetic gradients (`bg-gradient-to-r from-purple-500 to-indigo-600`).
- ❌ **NO Glassmorphism**: No `backdrop-blur-md`, translucent frosted glass cards, or gradient borders.
- ❌ **NO Gradient Blobs / Ambient Meshes**: No floating blurred background spheres.
- ❌ **NO Giant Hero Headings Inside CRM**: Titles must stay strictly within the 18px–24px range.
- ❌ **NO Card-Per-Field Anti-Pattern**: Do not wrap each individual key-value pair into its own floating rounded card. Use structured grid tables with hairline dividers.
- ❌ **NO Pill Buttons Everywhere**: Buttons must have crisp 4px–6px corners, not capsule/pill shapes.
- ❌ **NO Decorative Emojis**: Do not use emojis (🚀, 💡, 🩺, ✨) as UI iconography. Use professional, clean Heroicons / Lucide outlines.
- ❌ **NO Fake Analytics / Meaningless Gauges**: Do not render fake ring charts or filler graphs unless tied to actual recorded clinical metrics.
- ❌ **NO Cavernous Empty Whitespace**: Spacing must remain disciplined and dense.
- ❌ **NO Marketing Landing Page Patterns Inside CRM**: The CRM is a clinical workstation, not a promotional homepage.

---

## 11. Design System Governance & Reuse Invariant

1. **Mandatory Reuse**: Every new screen, modal, slide-over, or widget created in Chuklov (including all upcoming M10 AI workflows, scenario inspectors, and client portal enhancements) **MUST** directly reuse the tokens, layout structures, and component rules established in this `DESIGN.md`.
2. **No Per-Feature Reinvention**: Developers and AI agents are strictly forbidden from inventing arbitrary colors, card styles, or layouts for new features.
3. **Epistemic Invariant**: Any future AI-generated suggestion, triage classification, or automated summary must preserve the semantic distinction between confirmed specialist facts and machine proposals as specified in Section 7.
