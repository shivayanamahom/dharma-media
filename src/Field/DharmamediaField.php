<?php

namespace Dharma\Plugin\System\DharmaMedia\Field;

use Dharma\Plugin\System\DharmaMedia\Upload\UploadSettings;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\MediaField;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

defined('_JEXEC') or die;

final class DharmamediaField extends MediaField
{
    protected $type = 'Dharmamedia';

    protected function getInput()
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();

        if ($this->readonly || $this->disabled || !$user || $user->guest || !$user->authorise('core.create', 'com_media')) {
            return parent::getInput();
        }

        $app->getLanguage()->load('plg_system_dharmamedia', JPATH_ADMINISTRATOR);
        $document = $app->getDocument();
        $assets = $document->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('plg_system_dharmamedia');
        $assets->useStyle('plg_system_dharmamedia.upload')->useScript('plg_system_dharmamedia.upload');

        $settings = UploadSettings::fromPlugin();
        $context = $this->form->getName();
        $field = $this->fieldname;
        $author = (int) $this->form->getValue($this->authorField);
        $layout = new FileLayout('uploader', dirname(__DIR__, 2) . '/layouts');

        return $layout->render([
            'native' => parent::getInput(),
            'url' => Uri::base(true) . '/index.php?option=com_ajax&group=system&plugin=dharmaMediaUpload&format=json&ignoreMessages=1',
            'context' => $context,
            'field' => $field,
            'author' => $author,
            'token' => Session::getFormToken(),
            'signature' => $settings->sign($context, $field, $author),
            'maxBytes' => $settings->maxBytes(),
            'id' => $this->id,
        ]);
    }
}
