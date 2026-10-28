<?php

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\EnsureAppointmentReminderDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Organization::query()->orderBy('id')->each(function (Organization $organization): void {
            app(EnsureAppointmentReminderDefaults::class)->handle($organization, includeReminderOffsets: false);
        });
        DB::table('appointment_reminders')->where('recipient_type', 'client')
            ->where(fn ($query) => $query->where(fn ($offset) => $offset->where('offset_value', 1)->where('offset_unit', 'days'))
                ->orWhere(fn ($offset) => $offset->where('offset_value', 2)->where('offset_unit', 'hours')))
            ->update(['medical_profile_prompt' => true]);
    }

    public function down(): void {}
};
