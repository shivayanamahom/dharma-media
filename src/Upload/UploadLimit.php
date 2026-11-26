<?php

namespace Dharma\Plugin\System\DharmaMedia\Upload;

defined('_JEXEC') or die;

final class UploadLimit
{
    private const ATTEMPTS_PER_MINUTE = 12;
    private const CONCURRENT_UPLOADS = 3;
    private readonly string $directory;

    public function __construct(string $tmp, string $secret)
    {
        if ($tmp === '' || !is_dir($tmp)) {
            throw new \RuntimeException('Joomla temporary directory is unavailable');
        }

        $this->directory = rtrim($tmp, '/\\') . '/dharmamedia-' . substr(hash('sha256', $secret), 0, 24);

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700) && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not create the upload lock directory');
        }

        if (is_link($this->directory)) {
            throw new \RuntimeException('Upload lock directory must be a local directory');
        }
    }

    public function run(int $userId, \Closure $upload): array
    {
        $user = $this->lock('user-' . $userId);

        if ($user === null) {
            throw new \OverflowException('PLG_SYSTEM_DHARMAMEDIA_ERROR_BUSY');
        }

        try {
            $now = time();
            $attempts = array_filter(
                array_map('intval', explode("\n", stream_get_contents($user, 512))),
                static fn (int $time): bool => $time > $now - 60 && $time <= $now
            );

            if (count($attempts) >= self::ATTEMPTS_PER_MINUTE) {
                throw new \OverflowException('PLG_SYSTEM_DHARMAMEDIA_ERROR_RATE');
            }

            for ($i = 0; $i < self::CONCURRENT_UPLOADS; $i++) {
                $slot = $this->lock('slot-' . $i);

                if ($slot === null) {
                    continue;
                }

                try {
                    $attempts[] = $now;
                    $data = implode("\n", $attempts);
                    if (!rewind($user) || fwrite($user, $data) !== strlen($data)
                        || !ftruncate($user, strlen($data)) || !fflush($user)) {
                        throw new \RuntimeException('Could not update the upload quota');
                    }

                    return $upload();
                } finally {
                    fclose($slot);
                }
            }

            throw new \OverflowException('PLG_SYSTEM_DHARMAMEDIA_ERROR_BUSY');
        } finally {
            fclose($user);
        }
    }

    private function lock(string $name)
    {
        $path = $this->directory . '/' . $name . '.lock';
        if (is_link($path) || !($stream = @fopen($path, 'c+b'))) {
            throw new \RuntimeException('Could not open the upload lock');
        }

        if (!flock($stream, LOCK_EX | LOCK_NB)) {
            fclose($stream);

            return null;
        }

        return $stream;
    }
}
