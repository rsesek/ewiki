<p>
<? if ($has_history): ?>
    This page does not exist any more.
    <? if (Config::ALLOW_EDIT): ?>
        You can
        <a href="<?= $page->getURL() ?>?action=edit" class="new">
        create it now</a>
        or
        <a href="<?= $page->getURL() ?>?action=history">
        look at its history</a>
        to see its previous contents.
    <? else: ?>
        You can
        <a href="<?= $page->getURL() ?>?action=history">
        look at its history</a>
        to see its previous contents.
    <? endif; ?>
<? else: ?>
    This page does not exist yet.
    <? if (Config::ALLOW_EDIT): ?>
        You can
        <a href="<?= $page->getURL() ?>?action=edit" class="new">
        create it now</a>.
    <? endif; ?>
<? endif; ?>
</p>
