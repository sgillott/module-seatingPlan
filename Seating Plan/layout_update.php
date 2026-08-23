<?php
/**
 * Gibbon, Flexible & Open School System
 * Copyright (C) 2010, Ross Parker
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * @category Module
 * @package  Gibbon\Module\SeatingPlan
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Module\SeatingPlan\FurnitureCatalogue;
use Gibbon\Module\SeatingPlan\LayoutComparison;
use Gibbon\Module\SeatingPlan\Domain\FurnitureGateway;
use Gibbon\Module\SeatingPlan\Domain\RoomLayoutGateway;
use Gibbon\Module\SeatingPlan\Domain\SeatingPlanGateway;

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/layout_update.php')
    == false
) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));

    return;
}

$page->breadcrumbs
    ->add(__('Room Layouts'), 'layouts.php')
    ->add(__('Updated Layout'));

$seatingPlanRoomLayoutID = $_GET['seatingPlanRoomLayoutID'] ?? '';

if ($seatingPlanRoomLayoutID === '') {
    $page->addError(__('You have not specified one or more required parameters.'));

    return;
}

$roomLayoutGateway = $container->get(RoomLayoutGateway::class);
$layout = $roomLayoutGateway->getLayoutByID($seatingPlanRoomLayoutID);

if (empty($layout)) {
    $page->addError(__('The specified record does not exist.'));

    return;
}

// Only the owner of the copy decides what happens to it. Nobody else has a
// stake in whether they take the newer version or keep their own.
if ($layout['gibbonPersonIDOwner'] != $session->get('gibbonPersonID')) {
    $page->addError(__('You do not have access to this action.'));

    return;
}

$update = $roomLayoutGateway->getPendingUpdate($seatingPlanRoomLayoutID);

if (empty($update)) {
    echo Format::alert(
        __('Your layout is already up to date with the one it was copied from.'),
        'success'
    );

    return;
}

$owner = Format::name(
    $update['title'] ?? '',
    $update['preferredName'] ?? '',
    $update['surname'] ?? '',
    'Staff'
);

$affected = $container->get(SeatingPlanGateway::class)
    ->countSeatedPlansByLayout($seatingPlanRoomLayoutID);

echo '<p>';
echo __(
    '{owner} has changed {source}, which your own layout {mine} was copied '
    . 'from. You can take their newer version, or keep yours as it is.',
    [
        'owner'  => '<b>'.htmlspecialchars($owner, ENT_QUOTES, 'UTF-8').'</b>',
        'source' => '<b>'.htmlspecialchars($update['name'], ENT_QUOTES, 'UTF-8').'</b>',
        'mine'   => '<b>'.htmlspecialchars($layout['name'], ENT_QUOTES, 'UTF-8').'</b>',
    ]
);
echo '</p>';

echo '<p>';
echo __(
    'Theirs was last changed on {when}.',
    ['when' => Format::dateTimeReadable($update['timestampModified'])]
);
echo '</p>';

/* -------------------------------------------------------------- preview */

// Both rooms, drawn side by side, so the choice is made by looking rather
// than by reading a description of furniture nobody can see.
$furnitureGateway = $container->get(FurnitureGateway::class);
$comparison = LayoutComparison::compare(
    $furnitureGateway->selectFurnitureByLayout($seatingPlanRoomLayoutID),
    $furnitureGateway->selectFurnitureByLayout($update['seatingPlanRoomLayoutID'])
);

$catalogue = json_decode(FurnitureCatalogue::toJson(), true);

/**
 * One preview panel: a caption and the room itself.
 *
 * @param string $title    What this side is.
 * @param string $subtitle A short note under the title.
 * @param array  $items    Marked furniture rows from LayoutComparison.
 * @param int    $cols     Room width in cells.
 * @param int    $rows     Room height in cells.
 * @param array  $catalogue The furniture definitions.
 *
 * @return string
 */
$previewPanel = function ($title, $subtitle, $items, $cols, $rows, $catalogue) {
    $payload = json_encode(
        [
            'gridCols'  => (int) $cols,
            'gridRows'  => (int) $rows,
            'items'     => array_values($items),
            'catalogue' => $catalogue,
        ]
    );

    return '<div class="sp-preview-panel">'
        .'<h4 class="sp-preview-title">'
        .htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h4>'
        .'<p class="sp-preview-note">'
        .htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8').'</p>'
        .'<div class="sp-preview" data-sp-preview data-payload="'
        .htmlspecialchars($payload, ENT_QUOTES, 'UTF-8').'"></div>'
        .'</div>';
};

echo '<div class="sp-preview-pair">';
echo $previewPanel(
    __('Yours now'),
    $comparison['removed'] > 0
        ? __n(
            '{count} piece outlined in red would go',
            '{count} pieces outlined in red would go',
            $comparison['removed'],
            ['count' => $comparison['removed']]
        )
        : __('Nothing here would be lost'),
    $comparison['mine'],
    $layout['gridCols'],
    $layout['gridRows'],
    $catalogue
);
echo $previewPanel(
    __('Theirs'),
    $comparison['added'] > 0
        ? __n(
            '{count} piece outlined in green would arrive',
            '{count} pieces outlined in green would arrive',
            $comparison['added'],
            ['count' => $comparison['added']]
        )
        : __('Nothing new would arrive'),
    $comparison['theirs'],
    $update['gridCols'],
    $update['gridRows'],
    $catalogue
);
echo '</div>';

if ($comparison['added'] === 0 && $comparison['removed'] === 0) {
    echo Format::alert(
        __(
            'The furniture is identical. Taking their version would change '
            . 'nothing except stopping this prompt.'
        ),
        'message'
    );
}

$scriptBase = $session->get('absoluteURL').'/modules/'
    .rawurlencode('Seating Plan').'/js/';
$scriptVersion = rawurlencode($session->get('version', ''));

foreach (['furnitureArt.js', 'furnitureBackdrop.js', 'layoutPreview.js'] as $script) {
    echo '<script type="text/javascript" src="'
        .htmlspecialchars(
            $scriptBase.$script.'?v='.$scriptVersion,
            ENT_QUOTES,
            'UTF-8'
        ).'"></script>';
}

// The page can arrive as an htmx swap, where DOMContentLoaded has already
// fired and the script may itself be cached, so the draw is asked for
// explicitly as well.
echo '<script type="text/javascript">'
    .'if (window.SeatingPlanLayoutPreview) '
    .'{ window.SeatingPlanLayoutPreview.renderAll(); }</script>';

/* ------------------------------------------------------------- decisions */

if ($affected['plans'] > 0) {
    echo Format::alert(
        __(
            'Taking their version replaces your furniture. {students} '
            . 'students are seated in this room across {plans} seating '
            . 'plans; each one will be moved to the nearest free chair in '
            . 'the new arrangement, and anybody with no chair left will '
            . 'need seating again.',
            [
                'students' => $affected['seats'],
                'plans'    => $affected['plans'],
            ]
        ),
        'warning'
    );
} else {
    echo '<p>';
    echo __('Nobody is seated in this room yet, so nothing else is affected.');
    echo '</p>';
}

$moduleURL = $session->get('absoluteURL').'/modules/'.$session->get('module');

$acceptForm = Form::create(
    'layoutUpdateAccept',
    $moduleURL.'/layout_updateProcess.php?action=accept'
        .'&seatingPlanRoomLayoutID='.$seatingPlanRoomLayoutID
);
$acceptForm->addHiddenValue('address', $session->get('address'));

$acceptForm->addRow()->addHeading('accept', __('Take Their Version'));
$acceptForm->addRow()->addContent(
    __(
        'Your layout keeps its own name and stays yours. Only the furniture '
        . 'and room size are replaced.'
    )
);

$row = $acceptForm->addRow();
$row->addFooter();
$row->addSubmit(__('Take Their Version'));

echo $acceptForm->getOutput();

$dismissForm = Form::create(
    'layoutUpdateDismiss',
    $moduleURL.'/layout_updateProcess.php?action=dismiss'
        .'&seatingPlanRoomLayoutID='.$seatingPlanRoomLayoutID
);
$dismissForm->addHiddenValue('address', $session->get('address'));

$dismissForm->addRow()->addHeading('dismiss', __('Keep Mine'));
$dismissForm->addRow()->addContent(
    __(
        'Nothing changes. You will not be asked about this version again, '
        . 'but you will be told if they change it once more.'
    )
);

$row = $dismissForm->addRow();
$row->addFooter();
$row->addSubmit(__('Keep Mine'));

echo $dismissForm->getOutput();
