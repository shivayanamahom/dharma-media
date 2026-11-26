<?php

namespace Dharma\Plugin\System\DharmaMedia\Upload;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Helper\MediaHelper;

defined('_JEXEC') or die;

final class ImageUpload
{
    private const FORMATS = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

    public function __construct(private readonly UploadSettings $settings)
    {
    }

    public function save(array $file, string $context, string $field, int $author): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $this->fail('SIZE');
        }

        if ($error !== UPLOAD_ERR_OK || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $this->fail('UPLOAD');
        }

        $source = $file['tmp_name'];
        $size = filesize($source);

        if ($size > $this->settings->maxBytes()) {
            $this->fail('SIZE');
        }

        $info = @getimagesize($source);

        if (!$info || !isset(self::FORMATS[$info[2]])) {
            $this->fail('FORMAT');
        }

        [$width, $height, $format] = $info;

        if ($this->isAnimated($source, $format)) {
            $this->fail('ANIMATION');
        }

        $inputExtension = self::FORMATS[$format];
        $name = (string) $file['name'];

        if ($format === IMAGETYPE_JPEG && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'jpeg') {
            $inputExtension = 'jpeg';
        }

        $outputExtension = $this->settings->params->get('convert_webp', 1) ? 'webp' : $inputExtension;

        $this->checkMediaPolicy($source, $size, $inputExtension);
        [$adapter, $folders] = $this->settings->destination($context, $field, $author);

        $orientation = 1;
        if ($format === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $orientation = (int) (@exif_read_data($source)['Orientation'] ?? 1);
        }

        [$outputWidth, $outputHeight] = $this->fit($width, $height, $orientation);
        $this->checkMemory($width, $height, $outputWidth, $outputHeight);

        $stream = tmpfile();
        if ($stream === false) {
            $this->fail('STORAGE');
        }

        try {
            $this->encode($source, $format, $orientation, $outputWidth, $outputHeight, $outputExtension, $stream);
            $stat = fstat($stream);
            $encoded = stream_get_meta_data($stream)['uri'];

            $writtenImage = @getimagesize($encoded);
            $expectedFormat = $outputExtension === 'webp' ? IMAGETYPE_WEBP : $format;

            if (!$stat || !$stat['size'] || !$writtenImage
                || [$writtenImage[0], $writtenImage[1], $writtenImage[2]] !== [$outputWidth, $outputHeight, $expectedFormat]) {
                $this->fail('ENCODE');
            }

            if ($stat['size'] > $this->settings->maxBytes()) {
                $this->fail('SIZE');
            }

            $this->checkMediaPolicy($encoded, $stat['size'], $outputExtension);
            $directory = $this->ensureFolders($folders);
            $filename = $this->write($stream, $stat['size'], $directory, $this->stem($name), $outputExtension);
        } finally {
            fclose($stream);
        }

        $path = implode('/', [...$folders, $filename]);
        $relativeFolder = implode('/', array_slice($folders, 1));
        $adapterPath = ltrim($relativeFolder . '/' . $filename, '/');
        $value = str_replace(' ', '%20', $path)
            . "#joomlaImage://local-$adapter/$adapterPath?width=$outputWidth&height=$outputHeight";

        return [
            'value' => $value,
            'name' => $filename,
            'folder' => "local-$adapter:/$relativeFolder",
            'width' => $outputWidth,
            'height' => $outputHeight,
        ];
    }

    private function checkMediaPolicy(string $path, int $size, string $extension): void
    {
        $configured = ComponentHelper::getParams('com_media')->get('image_extensions', 'bmp,gif,jpg,jpeg,png,webp');
        $allowed = array_map('trim', explode(',', strtolower((string) $configured)));

        // Joomla's filename validation transliterates Unicode. Check its upload rules on the
        // validated extension and actual bytes. The stored name is sanitized separately.
        if (!in_array($extension, $allowed, true) || !(new MediaHelper())->canUpload([
            'name' => 'image.' . $extension,
            'tmp_name' => $path,
            'size' => $size,
        ])) {
            $this->fail('MEDIA_POLICY');
        }
    }

    private function isAnimated(string $path, int $format): bool
    {
        if ($format === IMAGETYPE_JPEG) {
            return false;
        }

        $input = fopen($path, 'rb');
        if ($input === false) {
            $this->fail('UPLOAD');
        }

        try {
            if ($format === IMAGETYPE_WEBP) {
                $header = fread($input, 21);

                return strlen($header) === 21 && substr($header, 12, 4) === 'VP8X' && (ord($header[20]) & 2) !== 0;
            }

            fseek($input, 8);

            while (strlen($chunk = fread($input, 8)) === 8) {
                $type = substr($chunk, 4);
                if ($type === 'acTL') {
                    return true;
                }

                if ($type === 'IDAT' || $type === 'IEND') {
                    return false;
                }

                $length = unpack('Nlength', $chunk)['length'];
                if (fseek($input, $length + 4, SEEK_CUR) !== 0) {
                    break;
                }
            }

            return false;
        } finally {
            fclose($input);
        }
    }

    private function fit(int $width, int $height, int $orientation): array
    {
        if ($orientation >= 5 && $orientation <= 8) {
            [$width, $height] = [$height, $width];
        }

        $maxWidth = (int) $this->settings->params->get('max_width', 2000);
        $maxHeight = (int) $this->settings->params->get('max_height', 2000);
        $scale = min(1, $maxWidth > 0 ? $maxWidth / $width : 1, $maxHeight > 0 ? $maxHeight / $height : 1);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    private function checkMemory(int $width, int $height, int $outputWidth, int $outputHeight): void
    {
        $sourcePixels = $width * $height;
        $outputPixels = $outputWidth * $outputHeight;
        $peakPixels = max($sourcePixels + ($sourcePixels === $outputPixels ? 0 : $outputPixels), $outputPixels * 2);
        $memoryLimit = ini_parse_quantity(ini_get('memory_limit'));

        // Leave room for decoder/encoder buffers and concurrent Joomla objects in this request.
        $estimatedBytes = $peakPixels * 5 + 16 * 1048576;
        if ($sourcePixels > 32000000 || max($width, $height) > 16000
            || ($memoryLimit > 0 && $estimatedBytes > $memoryLimit - memory_get_usage(true))) {
            $this->fail('PIXELS');
        }
    }

    private function encode(string $source, int $format, int $orientation, int $width, int $height, string $extension, $stream): void
    {
        $decoder = match ($format) {
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
        };
        $encoder = match ($extension) {
            'jpg', 'jpeg' => 'imagejpeg',
            'png' => 'imagepng',
            'webp' => 'imagewebp',
        };

        if (!function_exists($decoder) || !function_exists($encoder)) {
            $this->fail('GD');
        }

        $image = @$decoder($source);
        if (!$image) {
            $this->fail('DECODE');
        }

        $rotate = $orientation >= 5 && $orientation <= 8;
        [$scaledWidth, $scaledHeight] = $rotate ? [$height, $width] : [$width, $height];

        if (imagesx($image) !== $scaledWidth || imagesy($image) !== $scaledHeight) {
            $scaled = imagecreatetruecolor($scaledWidth, $scaledHeight);
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            imagecopyresampled($scaled, $image, 0, 0, 0, 0, $scaledWidth, $scaledHeight, imagesx($image), imagesy($image));
            $image = $scaled;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };

        if ($angle !== 0) {
            $image = imagerotate($image, $angle, 0);
            if (!$image) {
                $this->fail('ENCODE');
            }
        }

        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagesavealpha($image, true);
        $quality = max(1, min(100, (int) $this->settings->params->get('quality', 84)));

        if (!@$encoder($image, $stream, $extension === 'png' ? 6 : $quality)) {
            $this->fail('ENCODE');
        }
    }

    private function ensureFolders(array $folders): string
    {
        $directory = JPATH_ROOT;

        foreach ($folders as $folder) {
            $directory .= '/' . $folder;
            clearstatcache(true, $directory);

            if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0755) && !is_dir($directory))) {
                $this->fail('STORAGE');
            }
        }

        return $directory;
    }

    private function stem(string $name): string
    {
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $stem = preg_replace('/[^\p{L}\p{M}\p{N}_ -]+/u', '-', $stem) ?? '';
        $stem = trim(mb_strcut($stem, 0, 150, 'UTF-8'), ' _-');

        return $stem !== '' ? $stem : 'image';
    }

    private function write($stream, int $size, string $directory, string $stem, string $extension): string
    {
        $random = (bool) $this->settings->params->get('random_prefix', 1);

        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $prefix = $random ? bin2hex(random_bytes(5)) . '-' : '';
            $suffix = !$random && $attempt > 0 ? '-' . ($attempt + 1) : '';
            $filename = $prefix . $stem . $suffix . '.' . $extension;
            $path = $directory . '/' . $filename;
            $target = @fopen($path, 'xb');

            if ($target === false) {
                if (file_exists($path) || is_link($path)) {
                    continue;
                }

                $this->fail('STORAGE');
            }

            rewind($stream);
            $copied = stream_copy_to_stream($stream, $target);
            $closed = fclose($target);

            if ($copied === $size && $closed) {
                return $filename;
            }

            @unlink($path);
            $this->fail('STORAGE');
        }

        $this->fail('COLLISION');
    }

    private function fail(string $code): never
    {
        throw new \DomainException('PLG_SYSTEM_DHARMAMEDIA_ERROR_' . $code);
    }
}
