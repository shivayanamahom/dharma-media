<?php

namespace Dharma\Plugin\System\DharmaMedia;

use Dharma\Plugin\System\DharmaMedia\Extension\DharmaMedia;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

defined('_JEXEC') or die;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, static function (): DharmaMedia {
            $plugin = new DharmaMedia((array) PluginHelper::getPlugin('system', 'dharmamedia'));
            $plugin->setApplication(Factory::getApplication());

            return $plugin;
        });
    }
};
