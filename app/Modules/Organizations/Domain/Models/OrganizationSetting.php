<?php

namespace App\Modules\Organizations\Domain\Models;

use App\Modules\Organizations\Domain\Enums\OrganizationSettingType;
use Database\Factories\OrganizationSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property-read Organization $organization
 * @property OrganizationSettingType $value_type
 * @property string|null $string_value
 * @property int|null $integer_value
 * @property bool|null $boolean_value
 */
#[Fillable(['setting_key', 'value_type', 'string_value', 'integer_value', 'boolean_value'])]
class OrganizationSetting extends Model
{
    /** @use HasFactory<OrganizationSettingFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function typedValue(): string|int|bool
    {
        return match ($this->value_type) {
            OrganizationSettingType::String => $this->string_value ?? throw new LogicException('String setting value is missing.'),
            OrganizationSettingType::Integer => $this->integer_value ?? throw new LogicException('Integer setting value is missing.'),
            OrganizationSettingType::Boolean => $this->boolean_value ?? throw new LogicException('Boolean setting value is missing.'),
        };
    }

    protected static function newFactory(): OrganizationSettingFactory
    {
        return OrganizationSettingFactory::new();
    }

    protected function casts(): array
    {
        return [
            'value_type' => OrganizationSettingType::class,
            'integer_value' => 'integer',
            'boolean_value' => 'boolean',
        ];
    }
}
