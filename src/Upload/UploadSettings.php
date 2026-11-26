<?php

namespace Dharma\Plugin\System\DharmaMedia\Upload;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Session\Session;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

final class UploadSettings
{
    public function __construct(public readonly Registry $params)
    {
    }

    public static function fromPlugin(): self
    {
        return new self(new Registry(PluginHelper::getPlugin('system', 'dharmamedia')->params ?? '{}'));
    }

    /** The Filesystem – Local folders have their own adapter IDs. */
    public static function mediaFolders(): array
    {
        $plugin = PluginHelper::getPlugin('filesystem', 'local');

        if (!$plugin) {
            return [];
        }

        $folders = (new Registry($plugin->params))->get('directories', '[{"directory":"images"},{"directory":"files"}]');
        $folders = is_string($folders) ? json_decode($folders) : $folders;
        $result = [];

        foreach ((array) $folders as $row) {
            $folder = (string) (((array) $row)['directory'] ?? '');

            if (self::validSegment($folder) && is_dir(JPATH_ROOT . '/' . $folder) && !is_link(JPATH_ROOT . '/' . $folder)) {
                $result[] = $folder;
            }
        }

        return array_unique($result);
    }

    public function maxBytes(): int
    {
        $limits = [
            max(1, min(64, (int) $this->params->get('max_mb', 12))) * 1048576,
            (int) (ComponentHelper::getParams('com_media')->get('upload_maxsize', 0) * 1048576),
            ini_parse_quantity(ini_get('upload_max_filesize')),
            max(0, ini_parse_quantity(ini_get('post_max_size')) - 4096),
        ];

        return min(array_filter($limits, static fn (int $limit): bool => $limit > 0));
    }

    public function sign(string $context, string $field, int $author): string
    {
        $app = Factory::getApplication();
        $payload = json_encode([$app->getName(), Session::getFormToken(), $context, $field, $author], JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $payload, (string) $app->get('secret'));
    }

    public function destination(string $context, string $field, int $author): array
    {
        $root = trim((string) $this->params->get('upload_root', ''));

        if ($root === '') {
            $root = trim((string) ComponentHelper::getParams('com_media')->get('image_path', 'images'), '/');
        }

        $date = Factory::getDate('now', new \DateTimeZone(Factory::getApplication()->get('offset', 'UTC')));
        $template = trim((string) $this->params->get('upload_path', 'dharma/{year}/{month}'), " /");
        $relative = strtr($template, [
            '{year}' => $date->format('Y', true),
            '{month}' => $date->format('m', true),
            '{day}' => $date->format('d', true),
            '{author}' => (string) $author,
            '{user}' => (string) Factory::getApplication()->getIdentity()->id,
            '{component}' => explode('.', $context, 2)[0],
            '{field}' => $field,
        ]);

        $folders = explode('/', $root);
        $adapter = $folders[0];

        if ($relative !== '') {
            array_push($folders, ...explode('/', $relative));
        }

        if (!in_array($adapter, self::mediaFolders(), true) || count($folders) > 16
            || array_filter($folders, static fn (string $folder): bool => !self::validSegment($folder))) {
            throw new \DomainException('PLG_SYSTEM_DHARMAMEDIA_ERROR_PATH');
        }

        return [$adapter, $folders];
    }

    private static function validSegment(string $segment): bool
    {
        return strlen($segment) <= 120 && !str_contains($segment, '..')
            && preg_match('/^[\p{L}\p{M}\p{N}_-](?:[\p{L}\p{M}\p{N}_. -]*[\p{L}\p{M}\p{N}_-])?$/uD', $segment) === 1;
    }
}
