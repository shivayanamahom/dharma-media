<?php

namespace Dharma\Plugin\System\DharmaMedia\Field;

use Dharma\Plugin\System\DharmaMedia\Upload\UploadSettings;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;

defined('_JEXEC') or die;

final class DharmamediarootField extends ListField
{
    protected $type = 'Dharmamediaroot';

    protected function getOptions()
    {
        $options = parent::getOptions();

        foreach (UploadSettings::mediaFolders() as $folder) {
            $options[] = HTMLHelper::_('select.option', $folder, $folder);
        }

        return $options;
    }
}
