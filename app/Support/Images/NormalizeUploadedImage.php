<?php

namespace App\Support\Images;

use Illuminate\Http\UploadedFile;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

final class NormalizeUploadedImage
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/heic' => ['heic', 'heif'],
        'image/heif' => ['heic', 'heif'],
    ];

    public function normalize(UploadedFile $file, int $maxBytes, ?int $maxPixels = null): NormalizedUploadedImage
    {
        if (! $file->isValid()) {
            throw $this->invalid();
        }

        $path = $file->getRealPath();
        $size = $file->getSize();

        if ($path === false || ! is_file($path) || $size === false || $size <= 0) {
            throw $this->invalid();
        }

        if ($size > $maxBytes) {
            throw new ImageNormalizationException(ImageNormalizationException::TOO_LARGE, $maxBytes, $maxPixels);
        }

        $sniffedMime = $this->sniffMimeType($path);
        if ($sniffedMime !== 'application/octet-stream' && ! str_starts_with($sniffedMime, 'image/')) {
            throw $this->invalid();
        }

        $manager = $this->manager();

        try {
            $image = $manager->decodePath($path);
            $sourceMimeType = $this->canonicalMimeType($image->origin()->mediaType());
        } catch (Throwable $exception) {
            throw $this->invalid($exception);
        }

        if ($sourceMimeType === null || ! array_key_exists($sourceMimeType, self::MIME_EXTENSIONS)) {
            throw $this->invalid();
        }

        $clientExtension = strtolower((string) $file->getClientOriginalExtension());
        if ($clientExtension !== '' && ! in_array($clientExtension, self::MIME_EXTENSIONS[$sourceMimeType], true)) {
            throw $this->invalid();
        }

        $width = $image->width();
        $height = $image->height();
        if ($maxPixels !== null && $width * $height > $maxPixels) {
            throw new ImageNormalizationException(ImageNormalizationException::TOO_LARGE_DIMENSIONS, $maxBytes, $maxPixels);
        }

        $isBrowserSafeSource = in_array($sourceMimeType, ['image/jpeg', 'image/png', 'image/webp'], true);
        $requiresNormalization = ! $isBrowserSafeSource
            || $this->requiresMetadataNormalization($path);

        if (! $requiresNormalization) {
            $contents = file_get_contents($path);
            if (! is_string($contents) || $this->sniffMimeTypeFromContents($contents) !== $sourceMimeType) {
                throw $this->invalid();
            }

            $extension = self::MIME_EXTENSIONS[$sourceMimeType][0];

            return new NormalizedUploadedImage(
                contents: $contents,
                mimeType: $sourceMimeType,
                extension: $extension,
                sizeBytes: strlen($contents),
                sourceMimeType: $sourceMimeType,
                width: $width,
                height: $height,
            );
        }

        try {
            $image->orient()->removeProfile();
            $encoded = $this->encode($image, $sourceMimeType);
            $contents = $encoded->toString();
        } catch (Throwable $exception) {
            throw $this->invalid($exception);
        }

        $outputMimeType = $sourceMimeType === 'image/heic' || $sourceMimeType === 'image/heif'
            ? 'image/webp'
            : $sourceMimeType;
        if ($this->sniffMimeTypeFromContents($contents) !== $outputMimeType) {
            throw $this->invalid();
        }

        return new NormalizedUploadedImage(
            contents: $contents,
            mimeType: $outputMimeType,
            extension: $outputMimeType === 'image/webp' ? 'webp' : self::MIME_EXTENSIONS[$outputMimeType][0],
            sizeBytes: strlen($contents),
            sourceMimeType: $sourceMimeType,
            width: $image->width(),
            height: $image->height(),
        );
    }

    private function manager(): ImageManager
    {
        if (extension_loaded('imagick')) {
            return new ImageManager(new ImagickDriver);
        }

        if (extension_loaded('gd')) {
            return new ImageManager(new GdDriver);
        }

        throw $this->invalid();
    }

    private function encode(ImageInterface $image, string $sourceMimeType): mixed
    {
        return match ($sourceMimeType) {
            'image/heic', 'image/heif', 'image/webp' => $image->encode(new WebpEncoder(85, true)),
            'image/jpeg' => $image->encode(new JpegEncoder(85, false, true)),
            'image/png' => $image->encode(new PngEncoder(false, false, true)),
            default => throw $this->invalid(),
        };
    }

    private function requiresMetadataNormalization(string $path): bool
    {
        if (! extension_loaded('imagick')) {
            return true;
        }

        try {
            $probe = new \Imagick;
            $probe->readImage($path);

            foreach ($probe as $frame) {
                if (! in_array($frame->getImageOrientation(), [
                    \Imagick::ORIENTATION_UNDEFINED,
                    \Imagick::ORIENTATION_TOPLEFT,
                ], true)) {
                    $probe->clear();
                    $probe->destroy();

                    return true;
                }

                foreach (array_keys($frame->getImageProfiles('*', false)) as $profile) {
                    if (! in_array(strtolower((string) $profile), ['icc', 'hdrgm'], true)) {
                        $probe->clear();
                        $probe->destroy();

                        return true;
                    }
                }

                foreach (array_keys($frame->getImageProperties('*', false)) as $property) {
                    if (preg_match('/\A(?:exif|iptc|xmp|comment|label|caption|png:(?:text|tEXt|zTXt|iTXt)|webp:)/i', (string) $property) === 1) {
                        $probe->clear();
                        $probe->destroy();

                        return true;
                    }
                }
            }

            $probe->clear();
            $probe->destroy();

            return false;
        } catch (Throwable) {
            if (isset($probe) && $probe instanceof \Imagick) {
                $probe->clear();
                $probe->destroy();
            }

            return true;
        }
    }

    private function canonicalMimeType(string $mimeType): ?string
    {
        return match (strtolower($mimeType)) {
            'image/jpg', 'image/pjpeg', 'image/x-jpeg' => 'image/jpeg',
            'image/x-png' => 'image/png',
            'image/x-webp' => 'image/webp',
            'image/x-heic' => 'image/heic',
            'image/x-heif' => 'image/heif',
            default => strtolower($mimeType),
        };
    }

    private function sniffMimeType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mimeType = finfo_file($finfo, $path);
        unset($finfo);

        return is_string($mimeType) ? $this->canonicalMimeType($mimeType) ?? 'application/octet-stream' : 'application/octet-stream';
    }

    private function sniffMimeTypeFromContents(string $contents): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mimeType = finfo_buffer($finfo, $contents);
        unset($finfo);

        return is_string($mimeType) ? $this->canonicalMimeType($mimeType) ?? 'application/octet-stream' : 'application/octet-stream';
    }

    private function invalid(?Throwable $previous = null): ImageNormalizationException
    {
        return new ImageNormalizationException(ImageNormalizationException::INVALID, previous: $previous);
    }
}
