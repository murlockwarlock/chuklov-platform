<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 15mm 16mm; }
        body { color: #1f2937; font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; line-height: 1.35; }
        h1 { color: #111827; font-size: 18pt; margin: 0 0 4mm; }
        h2 { border-bottom: 1px solid #d1d5db; color: #374151; font-size: 12pt; margin: 8mm 0 3mm; padding-bottom: 1.5mm; }
        h3 { color: #4b5563; font-size: 10pt; margin: 4mm 0 1.5mm; }
        p { margin: 1.5mm 0; white-space: pre-wrap; }
        .muted { color: #6b7280; }
        .meta { color: #6b7280; font-size: 8pt; margin-bottom: 6mm; }
        .grid { border: 1px solid #d1d5db; border-bottom: 0; display: table; width: 100%; }
        .row { display: table-row; }
        .cell { border-bottom: 1px solid #d1d5db; display: table-cell; padding: 2mm; vertical-align: top; width: 50%; }
        .label { color: #6b7280; font-size: 8pt; }
        .value { margin-top: 0.8mm; }
        .session { border: 1px solid #d1d5db; margin: 4mm 0; padding: 3mm; page-break-inside: avoid; }
        .session-header { color: #111827; font-weight: bold; }
        table { border-collapse: collapse; margin: 2mm 0; width: 100%; }
        th, td { border: 1px solid #d1d5db; padding: 1.5mm; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; color: #374151; font-weight: bold; }
        .metrics { color: #374151; font-size: 8.5pt; }
        .recommendation { border-left: 2px solid #c27a3d; margin: 3mm 0; padding: 1mm 0 1mm 3mm; page-break-inside: avoid; }
    </style>
</head>
<body>
    <h1>Анкета и карта клиента</h1>
    <p class="meta">Сформировано {{ $generatedAt }} · документ для специалиста</p>

    <h2>Личные данные</h2>
    <div class="grid">
        <div class="row">
            <div class="cell"><div class="label">ФИО</div><div class="value">{{ $client->full_name ?: 'Не указано' }}</div></div>
            <div class="cell"><div class="label">Дата рождения</div><div class="value">{{ $client->birth_date?->format('d.m.Y') ?: 'Не указана' }}</div></div>
        </div>
        <div class="row">
            <div class="cell"><div class="label">Телефон</div><div class="value">{{ $client->phone ?: 'Не указан' }}</div></div>
            <div class="cell"><div class="label">Email</div><div class="value">{{ $client->email ?: 'Не указан' }}</div></div>
        </div>
        <div class="row">
            <div class="cell"><div class="label">Страна</div><div class="value">{{ $client->country ?: 'Не указана' }}</div></div>
            <div class="cell"><div class="label">Город</div><div class="value">{{ $client->city ?: 'Не указан' }}</div></div>
        </div>
        <div class="row">
            <div class="cell"><div class="label">Дата обращения</div><div class="value">{{ $client->created_at?->format('d.m.Y') ?: 'Не указана' }}</div></div>
            <div class="cell"><div class="label">Дата формирования</div><div class="value">{{ $generatedAt }}</div></div>
        </div>
    </div>

    <h2>Жалобы и цели</h2>
    @if ($profile)
        <h3>Жалобы</h3>
        <p>{{ $profile->complaints ?? $profile->complaintsGoalsForCompatibility() ?? 'Не указаны' }}</p>
        <h3>Цели</h3>
        <p>{{ $profile->goals ?? 'Не указаны' }}</p>
    @else
        <p class="muted">Медицинская анкета пока не заполнена.</p>
    @endif

    <h2>Сеансы</h2>
    @forelse ($sessions as $session)
        <section class="session">
            <p class="session-header">{{ $session['date'] }} · {{ $session['specialist'] }}</p>
            <p class="muted">Статус: {{ $session['status'] }}</p>
            @if ($session['result'])
                <h3>Результат</h3>
                <p>{{ $session['result'] }}</p>
            @endif
            @if ($session['tests'] !== [])
                <h3>Результаты тестов</h3>
                <table>
                    <thead><tr><th>Тест</th><th>До</th><th>После</th></tr></thead>
                    <tbody>
                    @foreach ($session['tests'] as $test)
                        <tr>
                            <td>{{ $test['name'] }}</td>
                            <td>{{ $test['before'] ?: '—' }}{{ $test['before_abnormal'] ? ' · нарушение' : '' }}</td>
                            <td>{{ $test['after'] ?: '—' }}{{ $test['after_abnormal'] ? ' · нарушение' : '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            @if ($session['measurements'] !== [])
                <h3>Числовые показатели</h3>
                <table>
                    <tbody>
                    @foreach ($session['measurements'] as $measurement)
                        <tr><td>{{ $measurement['label'] }}</td><td>{{ $measurement['value'] }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            <h3>Показатели эффективности</h3>
            <p class="metrics">Снижение боли: {{ $session['effectiveness']['average_pain_reduction_percentage'] === null ? '—' : $session['effectiveness']['average_pain_reduction_percentage'].'%' }} · Жалобы устранены полностью: {{ $session['effectiveness']['fully_resolved_complaints'] }} · Нарушения до/после: {{ $session['effectiveness']['violations_before'] }}/{{ $session['effectiveness']['violations_after'] }} · Тесты с улучшением: {{ $session['effectiveness']['resolved_tests'] }}</p>
        </section>
    @empty
        <p class="muted">Сеансов пока нет.</p>
    @endforelse

    <h2>Рекомендации</h2>
    @forelse ($recommendations as $recommendation)
        <div class="recommendation">
            <p class="muted">{{ $recommendation['date'] }}</p>
            <p>{{ $recommendation['text'] }}</p>
        </div>
    @empty
        <p class="muted">Рекомендаций пока нет.</p>
    @endforelse
</body>
</html>
