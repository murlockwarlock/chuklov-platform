<?php

namespace Tests\Unit\Support;

use App\Support\Images\ImageNormalizationException;
use App\Support\Images\NormalizeUploadedImage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class NormalizeUploadedImageTest extends TestCase
{
    public function test_jpeg_png_and_webp_are_content_validated_and_return_truthful_output(): void
    {
        foreach (['jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp'] as $format => $extension) {
            $normalized = app(NormalizeUploadedImage::class)->normalize(
                $this->upload("photo.{$extension}", $this->gdImage($format)),
                15 * 1024 * 1024,
            );

            self::assertSame("image/{$format}", $normalized->mimeType);
            self::assertSame($extension, $normalized->extension);
            self::assertSame($normalized->mimeType, $this->sniff($normalized->contents));
            self::assertSame($normalized->mimeType, getimagesizefromstring($normalized->contents)['mime'] ?? null);
        }
    }

    public function test_extension_spoof_and_corrupt_heic_are_rejected(): void
    {
        $normalizer = app(NormalizeUploadedImage::class);

        try {
            $normalizer->normalize($this->upload('photo.jpg', $this->gdImage('png')), 15 * 1024 * 1024);
            self::fail('A mismatched image extension must be rejected.');
        } catch (ImageNormalizationException $exception) {
            self::assertSame(ImageNormalizationException::INVALID, $exception->reason);
        }

        try {
            $normalizer->normalize($this->upload('photo.heic', 'not an image'), 15 * 1024 * 1024);
            self::fail('A corrupt HEIC payload must be rejected.');
        } catch (ImageNormalizationException $exception) {
            self::assertSame(ImageNormalizationException::INVALID, $exception->reason);
        }
    }

    public function test_input_size_limit_is_applied_before_decode(): void
    {
        $contents = $this->gdImage('jpeg');

        $this->expectException(ImageNormalizationException::class);
        $this->expectExceptionMessage(ImageNormalizationException::TOO_LARGE);

        app(NormalizeUploadedImage::class)->normalize(
            $this->upload('photo.jpg', $contents),
            strlen($contents) - 1,
        );
    }

    public function test_heic_is_converted_to_browser_safe_webp_when_runtime_supports_the_codec(): void
    {
        $this->assertHeifFixtureIsConverted('heic');
    }

    public function test_heif_is_converted_to_browser_safe_webp_when_runtime_supports_the_codec(): void
    {
        $this->assertHeifFixtureIsConverted('heif');
    }

    private function assertHeifFixtureIsConverted(string $format): void
    {
        $fixturePath = base_path("tests/Fixtures/images/known-good-{$format}.{$format}");
        self::assertFileExists($fixturePath);
        $this->skipIfHeifFixtureCannotBeDecoded($fixturePath, strtoupper($format));

        $contents = file_get_contents($fixturePath);
        self::assertIsString($contents);
        $normalizer = app(NormalizeUploadedImage::class);

        $normalized = $normalizer->normalize(
            $this->upload("phone.{$format}", $contents),
            15 * 1024 * 1024,
        );

        self::assertSame('image/webp', $normalized->mimeType);
        self::assertSame('webp', $normalized->extension);
        self::assertSame('image/webp', $this->sniff($normalized->contents));
        self::assertSame('image/webp', getimagesizefromstring($normalized->contents)['mime'] ?? null);
        self::assertSame("image/{$format}", $normalized->sourceMimeType);
    }

    private function skipIfHeifFixtureCannotBeDecoded(string $fixturePath, string $format): void
    {
        if (! extension_loaded('imagick')) {
            self::markTestSkipped("The runtime cannot decode the known-good {$format} fixture because Imagick is unavailable.");
        }

        $decoded = false;
        $probe = new \Imagick;

        try {
            $probe->readImage($fixturePath);
            $decoded = $probe->getNumberImages() > 0
                && $probe->getImageWidth() > 0
                && $probe->getImageHeight() > 0;
        } catch (\Throwable) {
            $decoded = false;
        } finally {
            $probe->clear();
            $probe->destroy();
        }

        if (! $decoded) {
            self::markTestSkipped("The runtime cannot decode the known-good {$format} fixture.");
        }
    }

    public function test_orientation_is_applied_and_exif_metadata_is_removed_when_imagick_is_available(): void
    {
        if (! extension_loaded('imagick')) {
            self::markTestSkipped('The runtime does not expose Imagick.');
        }

        $path = tempnam(sys_get_temp_dir(), 'image-normalizer-');
        self::assertIsString($path);

        try {
            $image = new \Imagick;
            $image->newImage(2, 3, new \ImagickPixel('red'));
            $image->setImageFormat('jpeg');
            $jpeg = $image->getImagesBlob();
            $image->clear();
            $image->destroy();

            $tiff = "II*\0";
            $tiff .= pack('V', 8);
            $tiff .= pack('v', 2);
            $tiff .= pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', 6).pack('v', 0);
            $tiff .= pack('v', 0x8825).pack('v', 4).pack('V', 1).pack('V', 38);
            $tiff .= pack('V', 0);
            $tiff .= pack('v', 2);
            $tiff .= pack('v', 0x0001).pack('v', 2).pack('V', 2)."N\0\0\0";
            $tiff .= pack('v', 0x0003).pack('v', 2).pack('V', 2)."E\0\0\0";
            $tiff .= pack('V', 0);
            $exif = "Exif\0\0".$tiff;
            $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
            file_put_contents($path, $jpeg);

            $normalized = app(NormalizeUploadedImage::class)->normalize(
                new UploadedFile($path, 'phone.jpg', 'image/jpeg', UPLOAD_ERR_OK, true),
                15 * 1024 * 1024,
            );

            self::assertSame('image/jpeg', $normalized->mimeType);
            self::assertSame(3, $normalized->width);
            self::assertSame(2, $normalized->height);

            $probe = new \Imagick;
            $probe->readImageBlob($normalized->contents);
            self::assertNotContains('exif', array_map('strtolower', array_keys($probe->getImageProfiles('*', false))));
            self::assertSame(\Imagick::ORIENTATION_TOPLEFT, $probe->getImageOrientation());
            $probe->clear();
            $probe->destroy();
        } finally {
            @unlink($path);
        }
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private function gdImage(string $format): string
    {
        $image = imagecreatetruecolor(4, 3);
        imagefill($image, 0, 0, imagecolorallocate($image, 65, 105, 225));
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($image, null, 85),
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 85),
        };
        $contents = ob_get_clean();
        unset($image);

        return (string) $contents;
    }

    private function sniff(string $contents): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        self::assertNotFalse($finfo);
        $mime = finfo_buffer($finfo, $contents);
        unset($finfo);

        self::assertIsString($mime);

        return $mime;
    }
}
