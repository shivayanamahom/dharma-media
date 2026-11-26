<?php

namespace Dharma\Plugin\System\DharmaMedia\Upload;

use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\DispatcherInterface;

defined('_JEXEC') or die;

final class MediaSaveEvents
{
    private readonly DispatcherInterface $dispatcher;

    public function __construct()
    {
        $this->dispatcher = Factory::getApplication()->getDispatcher();
        PluginHelper::importPlugin('content', null, true, $this->dispatcher);
        PluginHelper::importPlugin('media-action', null, true, $this->dispatcher);
    }

    public function before($stream, int $size, string $adapter, string $path, string $name, string $extension): ?\stdClass
    {
        if (!$this->dispatcher->getListeners('onContentBeforeSave')
            && !$this->dispatcher->getListeners('onContentAfterSave')) {
            return null;
        }

        rewind($stream);
        $bytes = stream_get_contents($stream, $size);

        if ($bytes === false || strlen($bytes) !== $size) {
            throw new \RuntimeException('Could not read the processed image for the Joomla media event');
        }

        $file = (object) [
            'adapter' => $adapter,
            'path' => $path,
            'name' => $name,
            'extension' => $extension,
            'data' => $bytes,
        ];
        $original = (array) $file;
        $event = new BeforeSaveEvent('onContentBeforeSave', [
            'context' => 'com_media.file',
            'subject' => $file,
            'isNew' => true,
            'data' => $file,
        ]);

        $results = $this->dispatcher->dispatch('onContentBeforeSave', $event)->getArgument('result', []);

        // Only the validated output can be written. Additional metadata may be added for after-save listeners.
        if (in_array(false, $results, true)) {
            throw new \DomainException('PLG_SYSTEM_DHARMAMEDIA_ERROR_MEDIA_VETO');
        }

        foreach ($original as $key => $value) {
            if (!isset($file->$key) || $file->$key !== $value) {
                throw new \DomainException('PLG_SYSTEM_DHARMAMEDIA_ERROR_MEDIA_CHANGED');
            }
        }

        return $file;
    }

    public function after(?\stdClass $file): void
    {
        if ($file === null) {
            return;
        }

        try {
            $this->dispatcher->dispatch('onContentAfterSave', new AfterSaveEvent('onContentAfterSave', [
                'context' => 'com_media.file',
                'subject' => $file,
                'isNew' => true,
                'data' => $file,
            ]));
        } catch (\Throwable $e) {
            // Joomla has already stored the image. Keep the response usable so the caller does not upload it again.
            Log::add($e->getMessage(), Log::ERROR, 'plg_system_dharmamedia');
        }
    }
}
