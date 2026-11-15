<?php

namespace App\Modules\Sessions\Application\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'organization_id',
    'client_id',
    'medical_session_id',
    'recommendation_revision',
    'channel',
    'status',
    'idempotency_key',
    'content_hash',
    'provider_reference',
    'error_code',
    'claimed_at',
    'delivered_at',
    'failed_at',
])]
final class MedicalSessionRecommendationDelivery extends Model
{
    protected $table = 'medical_session_recommendation_deliveries';

    protected function casts(): array
    {
        return [
            'recommendation_revision' => 'integer',
            'claimed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
