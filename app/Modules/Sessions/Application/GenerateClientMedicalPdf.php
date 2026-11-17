<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Dompdf\Dompdf;
use Dompdf\Options;

final readonly class GenerateClientMedicalPdf
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private GetMedicalProfile $medicalProfile,
        private ListClientSessions $sessions,
        private GetSession $getSession,
        private CalculateSessionEffectiveness $effectiveness,
        private ListClientSessionRecommendations $recommendations,
    ) {}

    public function handle(User $actor, Client $client): string
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            abort(404);
        }

        $profile = $this->medicalProfile->handle($actor, $client);
        $sessionRows = [];
        foreach ($this->sessions->query($actor, $client)->get() as $session) {
            $data = $this->getSession->handle($actor, $session, $client);
            if ($data === null) {
                continue;
            }
            $sessionRows[] = [
                'date' => $data->occurredAt?->setTimezone($organization->defaultTimezone())->format('d.m.Y H:i')
                    ?? __('Дата не указана'),
                'specialist' => trim((string) $session->specialist?->display_name) ?: __('Не указан'),
                'status' => $data->status,
                'result' => $this->cleanText($data->result),
                'tests' => array_map(static fn ($test): array => [
                    'name' => $test->testName,
                    'before' => $test->beforeLabel,
                    'after' => $test->afterLabel,
                    'before_abnormal' => $test->beforeAbnormal,
                    'after_abnormal' => $test->afterAbnormal,
                ], $data->testResults),
                'effectiveness' => $this->effectiveness->handle($data)->toArray(),
                'measurements' => $this->exportMeasurements($data->measurements),
            ];
        }

        $recommendations = array_map(
            fn ($recommendation): array => [
                'date' => $recommendation->occurredAt?->setTimezone($organization->defaultTimezone())->format('d.m.Y H:i')
                    ?? __('Дата не указана'),
                'text' => $this->cleanText($recommendation->draft),
            ],
            $this->recommendations->handle($actor, $client),
        );

        $html = view('pdf.client-medical-questionnaire', [
            'client' => $client,
            'profile' => $profile,
            'sessions' => $sessionRows,
            'recommendations' => $recommendations,
            'generatedAt' => now()->setTimezone($organization->defaultTimezone())->format('d.m.Y H:i'),
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }

    private function cleanText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return list<array{label: string, value: string}> */
    private function exportMeasurements(?array $measurements): array
    {
        if (! is_array($measurements)) {
            return [];
        }

        $labels = [
            'sys' => 'SYS, мм рт. ст.',
            'dia' => 'DIA, мм рт. ст.',
            'pd' => 'PD, мм рт. ст.',
            'map' => 'MAP, мм рт. ст.',
            'pulse' => 'Пульс, уд/мин',
            'pi' => 'Индекс перфузии, %',
            'spo2' => 'Сатурация, %',
            'height_cm' => 'Рост, см',
            'weight_kg' => 'Вес, кг',
            'bmi' => 'ИМТ',
            'impedance' => 'Импеданс',
            'waist_cm' => 'Талия, см',
            'hips_cm' => 'Бёдра, см',
            'chest_cm' => 'Грудь, см',
            'right_thigh_cm' => 'Бедро справа, см',
            'left_thigh_cm' => 'Бедро слева, см',
        ];
        $result = [];
        foreach (['before', 'after'] as $phase) {
            if ($phase === 'after' && ! ($measurements['after_enabled'] ?? false)) {
                continue;
            }
            $values = $measurements[$phase]['vitals'] ?? null;
            if (! is_array($values)) {
                continue;
            }
            foreach ($values as $key => $value) {
                if (! array_key_exists($key, $labels) || ! is_scalar($value) || $value === '') {
                    continue;
                }
                $result[] = [
                    'label' => ($phase === 'after' ? 'После: ' : 'До: ').$labels[$key],
                    'value' => (string) $value,
                ];
            }
        }

        return $result;
    }
}
