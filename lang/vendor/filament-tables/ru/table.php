<?php

return [
    'column_manager' => [
        'actions' => [
            'reorder' => [
                'label' => 'Изменить порядок столбца',
            ],
        ],
    ],
    'columns' => [
        'icon' => [
            'boolean' => [
                'true' => 'Да',
                'false' => 'Нет',
            ],
        ],
    ],
    'actions' => [
        'reorder_record' => [
            'label' => 'Изменить порядок записи :key',
        ],
        'toggle_record_content' => [
            'label' => 'Развернуть или свернуть запись :key',
        ],
    ],
    'loading' => 'Загрузка...',
    'result_count' => '{0} Нет результатов|{1} :count результат|[2,4] :count результата|[5,*] :count результатов',
];
