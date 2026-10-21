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
        }
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
