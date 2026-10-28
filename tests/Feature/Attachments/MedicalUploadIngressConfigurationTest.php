<?php

namespace Tests\Feature\Attachments;

use InvalidArgumentException;
use Tests\TestCase;

final class MedicalUploadIngressConfigurationTest extends TestCase
{
    public function test_nginx_and_php_limits_allow_a_maximum_size_medical_file_with_multipart_overhead(): void
    {
        $uploadConfiguration = parse_ini_file(base_path('docker/php/uploads.ini'), false, INI_SCANNER_RAW);

        self::assertIsArray($uploadConfiguration);

        $uploadLimit = $this->sizeInBytes((string) $uploadConfiguration['upload_max_filesize']);
        $requestLimit = $this->sizeInBytes((string) $uploadConfiguration['post_max_size']);

        self::assertGreaterThan($uploadLimit, $requestLimit);
        $hostLogFormat = file_get_contents(base_path('docker/host-nginx/00-medical-attachment-log-format.conf'));
        $applicationConfiguration = file_get_contents(base_path('docker/php/nginx.conf'));

        self::assertIsString($hostLogFormat);
        self::assertMatchesRegularExpression('/log_format\s+medical_attachment_safe\s+\'\$request_method\s+\$status\s+\$body_bytes_sent\s+\$request_time\'/s', $hostLogFormat);
        self::assertDoesNotMatchRegularExpression('/\$(?:request_uri|args|query_string|arg_[a-z_]+)/i', $hostLogFormat);
        self::assertIsString($applicationConfiguration);
        self::assertMatchesRegularExpression('/log_format\s+medical_attachment_safe\s+\'\$request_method\s+\$status\s+\$body_bytes_sent\s+\$request_time\'/s', $applicationConfiguration);

        foreach ([
            'docker/host-nginx/chuklov-staging-tls.conf',
            'docker/php/nginx.conf',
        ] as $configurationPath) {
            $configuration = file_get_contents(base_path($configurationPath));

            self::assertIsString($configuration);
            self::assertSame(1, preg_match_all('/^\s*client_max_body_size\s+(\d+[KMG]?)\s*;/mi', $configuration, $matches));
            self::assertGreaterThanOrEqual(
                $requestLimit,
                $this->sizeInBytes($matches[1][0]),
                $configurationPath,
            );
            self::assertMatchesRegularExpression('/location\s+\^~\s+\/portal\/medical-attachments\s*\{[^}]*access_log\s+[^;]+medical_attachment_safe\s*;/s', $configuration, $configurationPath);
        }

        $hostConfiguration = file_get_contents(base_path('docker/host-nginx/chuklov-staging-tls.conf'));
        self::assertIsString($hostConfiguration);
        self::assertMatchesRegularExpression('/^\s*listen\s+127\.0\.0\.1:18081\s+ssl\s*;/m', $hostConfiguration);
        self::assertMatchesRegularExpression('/location\s+@medical_attachment_app\s*\{[^}]*access_log\s+[^;]+medical_attachment_safe\s*;/s', $hostConfiguration);
        self::assertMatchesRegularExpression('/location\s+~\s+\\\.php\$\s*\{\s*access_log\s+[^;]+medical_attachment_safe\s*;/s', $applicationConfiguration);
    }

    private function sizeInBytes(string $size): int
    {
        if (preg_match('/\A(\d+)([KMG]?)\z/i', $size, $matches) !== 1) {
            throw new InvalidArgumentException('The configured upload limit is invalid.');
        }

        $multiplier = match (strtoupper($matches[2])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };

        return (int) $matches[1] * $multiplier;
    }
}
