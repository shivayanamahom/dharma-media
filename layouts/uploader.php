<?php

use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$label = static fn (string $key): string => Text::_('PLG_SYSTEM_DHARMAMEDIA_' . $key);
$max = rtrim(rtrim(number_format($displayData['maxBytes'] / 1048576, 2, '.', ''), '0'), '.');
$helpId = $displayData['id'] . '-dharmamedia-help';
?>
<dharma-media-upload class="dharma-media"
    data-url="<?= $escape($displayData['url']) ?>"
    data-context="<?= $escape($displayData['context']) ?>"
    data-field="<?= $escape($displayData['field']) ?>"
    data-author="<?= $escape($displayData['author']) ?>"
    data-token="<?= $escape($displayData['token']) ?>"
    data-signature="<?= $escape($displayData['signature']) ?>"
    data-max-bytes="<?= $escape($displayData['maxBytes']) ?>"
    data-server-label="<?= $escape($label('SERVER')) ?>"
    data-size-error="<?= $escape($label('ERROR_SIZE')) ?>"
    data-upload-error="<?= $escape($label('ERROR_NETWORK')) ?>"
    data-pending="<?= $escape($label('PENDING')) ?>"
    data-uploaded="<?= $escape($label('UPLOADED')) ?>">
    <div class="dharma-media__drop" role="group" tabindex="0" aria-label="<?= $escape($label('DROP')) ?>"
        aria-describedby="<?= $escape($helpId) ?>">
        <span class="dharma-media__icon icon-upload" aria-hidden="true"></span>
        <div class="dharma-media__guide">
            <span class="dharma-media__title"><?= $escape($label('DROP')) ?></span>
            <span class="dharma-media__hint" id="<?= $escape($helpId) ?>">
                <?= $escape(Text::sprintf('PLG_SYSTEM_DHARMAMEDIA_HINT', $max)) ?>
            </span>
        </div>
        <input class="dharma-media__file" type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" hidden>
        <button class="dharma-media__choose btn btn-sm btn-primary" type="button">
            <?= $escape($label('CHOOSE')) ?>
        </button>
    </div>
    <?= $displayData['native'] ?>
    <progress class="dharma-media__progress" aria-label="<?= $escape($label('PENDING')) ?>" max="100" hidden></progress>
    <div class="dharma-media__status" role="status"></div>
</dharma-media-upload>
