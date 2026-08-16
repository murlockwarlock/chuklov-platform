<?php

return [
    'embedding' => [
        'provider' => env('RAG_EMBEDDING_PROVIDER', 'openai'),
        'model' => env('RAG_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 1536),
        'configuration_version' => env('RAG_EMBEDDING_CONFIGURATION_VERSION', 'v1'),
        'timeout_seconds' => (int) env('RAG_EMBEDDING_TIMEOUT_SECONDS', 30),
    ],
    'chunking' => [
        'strategy' => 'normalized-character-window',
        'version' => 'v1',
        'target_characters' => 1200,
        'maximum_characters' => 1600,
        'overlap_characters' => 160,
    ],
    'retrieval' => [
        'default_top_k' => 5,
        'maximum_top_k' => 20,
        'minimum_similarity' => 0.0,
    ],
    'processing_stale_after_seconds' => 1800,
    'uploads' => [
        'disk' => 'private',
        'maximum_kilobytes' => 2048,
        'maximum_extracted_characters' => 500000,
        'allowed_mime_types' => ['text/plain', 'text/markdown'],
        'allowed_extensions' => ['txt', 'md', 'markdown'],
    ],
];
