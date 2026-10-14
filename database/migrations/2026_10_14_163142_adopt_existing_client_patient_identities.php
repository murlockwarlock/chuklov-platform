<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')->select(['id', 'organization_id', 'first_name', 'last_name', 'middle_name', 'gender', 'birth_date'])
            ->orderBy('id')->chunkById(100, function ($clients): void {
                foreach ($clients as $candidate) {
                    DB::transaction(function () use ($candidate): void {
                        $client = DB::table('clients')->where('organization_id', $candidate->organization_id)->where('id', $candidate->id)->lockForUpdate()->first();
                        if ($client === null) {
                            return;
                        }
                        DB::table('patient_profiles')->insertOrIgnore([
                            'organization_id' => $client->organization_id, 'owner_client_id' => $client->id, 'clinical_client_id' => $client->id,
                            'linked_account_client_id' => $client->id, 'relationship' => 'SELF', 'first_name' => $client->first_name,
                            'last_name' => $client->last_name, 'middle_name' => $client->middle_name,
                            'gender' => in_array($client->gender, ['MALE', 'FEMALE'], true) ? $client->gender : null,
                            'birth_date' => $client->birth_date, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $patientId = DB::table('patient_profiles')->where('organization_id', $client->organization_id)->where('clinical_client_id', $client->id)->value('id');
                        if ($patientId === null) {
                            throw new LogicException('A patient identity could not be adopted.');
                        }
                        foreach (['bookings', 'medical_profiles', 'medical_sessions'] as $table) {
                            DB::table($table)->where('organization_id', $client->organization_id)->where('client_id', $client->id)
                                ->whereNull('patient_profile_id')->update(['patient_profile_id' => $patientId]);
                        }
                    });
                }
            });
    }

    public function down(): void
    {
        throw new LogicException('Existing patient history associations are retained by forward-only migrations.');
    }
};
