<?php

namespace Dharma\Plugin\System\DharmaMedia\Extension;

use Dharma\Plugin\System\DharmaMedia\Upload\ImageUpload;
use Dharma\Plugin\System\DharmaMedia\Upload\UploadSettings;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

final class DharmaMedia extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onContentPrepareForm' => ['prepareForm', -100],
            'onAjaxDharmaMediaUpload' => 'upload',
        ];
    }

    public function prepareForm(PrepareFormEvent $event): void
    {
        $app = $this->getApplication();
        $form = $event->getForm();
        $formName = $form->getName();

        if ($app->isClient('site')) {
            if ($formName !== 'com_content.article' || !$this->params->get('site_article', 0)) {
                return;
            }
        } elseif (!$app->isClient('administrator')) {
            return;
        }

        $scope = match (true) {
            $formName === 'com_content.article' => 'article',
            str_starts_with($formName, 'com_categories.category') => 'category',
            $formName === 'com_tags.tag' => 'tag',
            $formName === 'com_contact.contact' => 'contact',
            $formName === 'com_banners.banner' => 'banner',
            $formName === 'com_newsfeeds.newsfeed' => 'newsfeed',
            in_array($formName, ['com_menus.item', 'com_menus.item.admin'], true) => 'menu',
            default => '',
        };

        if ($scope === '') {
            $extra = preg_split('/[\s,]+/', (string) $this->params->get('extra_forms', ''), -1, PREG_SPLIT_NO_EMPTY);

            if (!in_array($formName, $extra, true)) {
                return;
            }
        } elseif ($scope !== 'article' && !$this->params->get($scope, (int) ($scope === 'category'))) {
            return;
        }

        FormHelper::addFieldPrefix('Dharma\\Plugin\\System\\DharmaMedia\\Field');

        foreach ($form->getXml()->xpath('//field[not(ancestor::field)]') as $field) {
            if (strtolower((string) $field['type']) !== 'media'
                || (isset($field['types']) && trim((string) $field['types']) !== 'images')) {
                continue;
            }

            if ($scope === 'article') {
                $setting = match ((string) $field['name']) {
                    'image_intro' => 'article_intro',
                    'image_fulltext' => 'article_full',
                    default => '',
                };

                if ($setting === '' || !$this->params->get($setting, 1)) {
                    continue;
                }
            }

            $field['type'] = 'dharmamedia';
        }
    }

    public function upload(AjaxEvent $event): void
    {
        $app = $this->getApplication();
        $input = $app->getInput();
        $user = $app->getIdentity();
        $this->loadLanguage();

        if (!$app->isClient('administrator') && !($app->isClient('site') && $this->params->get('site_article', 0))) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if ($input->getMethod() !== 'POST' || $user->guest || !$user->authorise('core.create', 'com_media')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if (!Session::checkToken()) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN_NOTICE'), 403);
        }

        $settings = new UploadSettings($this->params);
        $context = $input->post->getString('context', '');
        $field = $input->post->getString('field', '');
        $author = $input->post->getUint('author');

        if (!hash_equals($settings->sign($context, $field, $author), $input->post->getString('signature', ''))) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN_NOTICE'), 403);
        }

        try {
            $event->addResult((new ImageUpload($settings))->save(
                $input->files->get('image', [], 'array'),
                $context,
                $field,
                $author ?: (int) $user->id
            ));
        } catch (\DomainException $e) {
            throw new \RuntimeException(Text::_($e->getMessage()), 400);
        } catch (\Throwable $e) {
            Log::add($e->getMessage(), Log::ERROR, 'plg_system_dharmamedia');

            throw new \RuntimeException(Text::_('PLG_SYSTEM_DHARMAMEDIA_ERROR_STORAGE'), 500);
        }
    }
}
