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
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Module\SeatingPlan\Domain\RoomLayoutGateway;

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/layout_transfer.php')
    == false
) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));

    return;
}

$page->breadcrumbs
    ->add(__('Room Layouts'), 'layouts.php')
    ->add(__('Import & Export'));

$gibbonPersonID = $session->get('gibbonPersonID');
$moduleURL = $session->get('absoluteURL').'/modules/'.$session->get('module');

echo '<p>';
echo __(
    'A room layout is just a drawing of furniture, so it travels well: send '
    . 'one to a colleague, keep a copy before rearranging a room, or move a '
    . 'set of rooms to another Gibbon installation.'
);
echo '</p>';

echo '<p>';
echo __(
    'An exported file holds the room size, the furniture, and the names of '
    . 'the layout and its room. It holds no students, no seating plans and '
    . 'no register - there is nothing in it that identifies anybody.'
);
echo '</p>';

/* ------------------------------------------------------------- export */

$layouts = $container->get(RoomLayoutGateway::class)
    ->selectLayoutsForTransfer($gibbonPersonID);

$exportForm = Form::create(
    'layoutExport',
    $moduleURL.'/layout_transferProcess.php?action=export'
);
$exportForm->setFactory(DatabaseFormFactory::create($pdo));
$exportForm->addHiddenValue('address', $session->get('address'));

$exportForm->addRow()->addHeading('export', __('Export'));

if (empty($layouts)) {
    $exportForm->addRow()->addContent(
        '<i>'.__('There are no room layouts to export yet.').'</i>'
    );
} else {
    $row = $exportForm->addRow();
    $row->addLabel('seatingPlanRoomLayoutIDList', __('Layouts'))
        ->description(__('Your own layouts, and any a colleague has shared.'));
    $row->addSelect('seatingPlanRoomLayoutIDList')
        ->fromArray($layouts)
        ->selectMultiple()
        ->setSize(min(12, max(4, count($layouts))))
        ->required();

    $row = $exportForm->addRow();
    $row->addFooter();
    $row->addSubmit(__('Download'));
}

echo $exportForm->getOutput();

/* ------------------------------------------------------------- import */

$importForm = Form::create(
    'layoutImport',
    $moduleURL.'/layout_transferProcess.php?action=import'
);
$importForm->setFactory(DatabaseFormFactory::create($pdo));
$importForm->addHiddenValue('address', $session->get('address'));

$importForm->addRow()->addHeading('import', __('Import'));

// The process file sends the real reason back rather than a generic
// failure: "that is not a layout file" and "that layout was drawn for a
// bigger room" need different things done about them.
if (!empty($_GET['importError'])) {
    echo Format::alert(
        __('Import failed').': '.htmlspecialchars(
            (string) $_GET['importError'],
            ENT_QUOTES,
            'UTF-8'
        ),
        'error'
    );
}

$importForm->addRow()->addContent(
    __(
        'Importing always creates a new layout belonging to you. It never '
        . 'changes or replaces a layout that is already there, including your '
        . 'own - so importing the same file twice gives you two copies rather '
        . 'than overwriting the first.'
    )
);

$row = $importForm->addRow();
$row->addLabel('layoutFile', __('File'))
    ->description(__('A JSON file exported from this page.'));
$row->addFileUpload('layoutFile')->required()->accepts(['.json']);

$row = $importForm->addRow();
$row->addLabel('gibbonSpaceID', __('Room'))
    ->description(__(
        'Which room to draw it in. The file remembers the room it came from '
        . 'by name only, which means nothing in another installation, so the '
        . 'room is always chosen here.'
    ));
$row->addSelectSpace('gibbonSpaceID')->required()->placeholder();

$row = $importForm->addRow();
$row->addLabel('name', __('Name'))
    ->description(__(
        'Leave blank to keep the name in the file. Ignored when the file '
        . 'holds more than one layout, since they cannot all share a name.'
    ));
$row->addTextField('name')->maxLength(40);

$row = $importForm->addRow();
$row->addLabel('shared', __('Share With Colleagues?'))
    ->description(__('A shared layout can be used by anyone teaching in that room.'));
$row->addYesNo('shared')->selected('Y')->required();

$row = $importForm->addRow();
$row->addFooter();
$row->addSubmit(__('Import'));

echo $importForm->getOutput();
