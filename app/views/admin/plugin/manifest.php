<?
# Lifter010: TODO
use Studip\Button, Studip\LinkButton;

?>
<h3>
    <?= _('Plugin-Details') ?>
</h3>

<table>
    <tr>
        <td>Name:</td>
        <td><?= htmlReady($plugin['name']) ?></td>
    </tr>
    <tr>
        <td>Klasse:</td>
        <td><?= $plugin['class'] ?></td>
    </tr>
    <tr>
        <td>Typ:</td>
        <td><?= join(', ', $plugin['type']) ?></td>
    </tr>
    <tr>
        <td>Origin:</td>
        <td><?= htmlReady($manifest['origin']) ?></td>
    </tr>
    <tr>
        <td>Version:</td>
        <td><?= htmlReady($manifest['version']) ?></td>
    </tr>
    <tr>
        <td>Beschreibung:</td>
        <td><?= htmlReady($manifest['description']) ?></td>
    </tr>
</table>

<p>
    <?= LinkButton::create('<< '.  _("Zur�ck"), $controller->url_for('admin/plugin'), array('title' => _('zur�ck zur Plugin-Verwaltung')))?>
</p>

<?
$infobox_content = array(
    array(
        'kategorie' => _('Aktionen:'),
        'eintrag'   => array(
            array(
                'icon' => 'icons/16/black/schedule.png',
                'text' => '<a href="'.$controller->url_for('admin/plugin').'">'._('Verwaltung von Plugins').'</a>'
            )
        )
    ), array(
        'kategorie' => _('Hinweise:'),
        'eintrag'   => array(
            array(
                "icon" => "icons/16/black/info.png",
                'text' => _('Hier finden Sie weitere Informationen zum ausgew�hlten Plugin.')
            )
        )
    )
);

$infobox = array('picture' => 'sidebar/plugin-sidebar.png', 'content' => $infobox_content);
?>
