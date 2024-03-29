<form action="<?= URLHelper::getLink($url, array(), true) ?>" method="<?= $method?>">
    <?= ($method == 'post' ? CSRFProtection::tokenTag() : '') ?>
    <select class="sidebar-selectlist" size="<?= (int) $size ?: 8 ?>" name="<?= htmlReady($name) ?>" onKeyDown="if (event.keyCode === 13) { jQuery(this).closest('form')[0].submit(); }" <?= $size == 1 ? 'onchange' : 'onClick'?>="jQuery(this).closest('form')[0].submit();" size="10" style="max-width: 200px;cursor:pointer" class="text-top" aria-label="<?= _("Wählen Sie ein Objekt aus. Sie gelangen dann zur neuen Seite.") ?>">
    <? foreach ($elements as $element): ?>
        <option <?= $value == $element->getid() ? 'selected' : ''?> value="<?= htmlReady($element->getid()) ?>"><?= htmlReady(my_substr($element->getLabel(), 0, 30)) ?></option>
    <? endforeach; ?>
    </select>
</form>