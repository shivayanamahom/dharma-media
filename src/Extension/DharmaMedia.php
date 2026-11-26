<?php

namespace Dharma\Plugin\System\DharmaMedia\Extension;

use Dharma\Plugin\System\DharmaMedia\Upload\ImageUpload;
use Dharma\Plugin\System\DharmaMedia\Upload\UploadLimit;
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
        $form = $event->getForm();
        $formName = $form->getName();

        if (!$this->allowsForm($formName)) {
            return;
        }

        FormHelper::addFieldPrefix('Dharma\\Plugin\\System\\DharmaMedia\\Field');

        foreach ($form->getXml()->xpath('//field[not(ancestor::field)]') as $field) {
            if (strtolower((string) $field['type']) !== 'media'
                || (isset($field['types']) && trim((string) $field['types']) !== 'images')) {
                continue;
            }

            if (!$this->allowsField($formName, (string) $field['name'])) {
                continue;
            }

            $field['type'] = 'dharmamedia';
        }
    }

    private function allowsForm(string $formName): bool
    {
        $app = $this->getApplication();

        if ($app->isClient('site')) {
            return $formName === 'com_content.article' && (bool) $this->params->get('site_article', 0);
        }

        if (!$app->isClient('administrator')) {
            return false;
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
                return false;
            }
        } elseif ($scope !== 'article' && !$this->params->get($scope, (int) ($scope === 'category'))) {
            return false;
        }

        return true;
    }

    private function allowsField(string $formName, string $field): bool
    {
        if ($formName !== 'com_content.article') {
            return true;
        }

        return match ($field) {
            'image_intro' => (bool) $this->params->get('article_intro', 1),
            'image_fulltext' => (bool) $this->params->get('article_full', 1),
            default => false,
        };
    }

    public function upload(AjaxEvent $event): void
    {
        $app = $this->getApplication();
        $input = $app->getInput();
        $user = $app->getIdentity();
        $this->loadLanguage();

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

        if (!mb_check_encoding([$context, $field], 'UTF-8')
            || !$this->allowsForm($context) || !$this->allowsField($context, $field)
            || !hash_equals($settings->sign($context, $field, $author), $input->post->getString('signature', ''))) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN_NOTICE'), 403);
        }

        try {
            $limit = new UploadLimit((string) $app->get('tmp_path'), (string) $app->get('secret'));
            $event->addResult($limit->run((int) $user->id, fn () => (new ImageUpload($settings))->save(
                $input->files->get('image', [], 'array'),
                $context,
                $field,
                $author ?: (int) $user->id
            )));
        } catch (\OverflowException $e) {
            throw new \RuntimeException(Text::_($e->getMessage()), 429);
        } catch (\DomainException $e) {
            throw new \RuntimeException(Text::_($e->getMessage()), 400);
        } catch (\Throwable $e) {
            Log::add($e->getMessage(), Log::ERROR, 'plg_system_dharmamedia');

            throw new \RuntimeException(Text::_('PLG_SYSTEM_DHARMAMEDIA_ERROR_STORAGE'), 500);
        }
    }
}
