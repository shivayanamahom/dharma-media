<?php

namespace Dharma\Plugin\System\DharmaMedia\Field;

use Joomla\CMS\Form\Field\TextField;

defined('_JEXEC') or die;

final class DharmamediapathField extends TextField
{
    protected $type = 'Dharmamediapath';

    public function renderField($options = [])
    {
        $options['descClass'] = 'mt-2';

        return parent::renderField($options);
    }
}
