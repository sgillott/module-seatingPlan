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
use Gibbon\Http\Url;
use Gibbon\Services\Format;
use Gibbon\Tables\DataTable;
use Gibbon\Module\SeatingPlan\Domain\RoomLayoutGateway;

if (isActionAccessible($guid, $connection2, '/modules/Seating Plan/layouts.php') == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('Room Layouts'));

    echo '<p>';
    echo __(
        'A room layout is the furniture in a room: desks, chairs, the board, '
        . 'the door. Draw it once, then use it for as many seating plans as you '
        . 'like. Share a layout to let colleagues who teach in the same room use it too.'
    );
    echo '</p>';

    $gibbonPersonID = $session->get('gibbonPersonID');
    $search = $_GET['search'] ?? '';

    $roomLayoutGateway = $container->get(RoomLayoutGateway::class);

    $criteria = $roomLayoutGateway
        ->newQueryCriteria(true)
        ->searchBy($roomLayoutGateway->getSearchableColumns(), $search)
        ->sortBy(['spaceName', 'name'])
        ->fromPOST();

    // SEARCH FORM
    $form = Form::create('searchForm', $session->get('absoluteURL').'/index.php', 'get');
    $form->setTitle(__('Search'));
    $form->setClass('noIntBorder w-full');
    $form->addHiddenValue('q', '/modules/Seating Plan/layouts.php');

    $row = $form->addRow();
    $row->addLabel('search', __('Search For'))->description(__('Room or layout name'));
    $row->addTextField('search')->setValue($criteria->getSearchText());

    $row = $form->addRow();
    $row->addSearchSubmit($session, 'Clear Search');

    echo $form->getOutput();

    $layouts = $roomLayoutGateway->queryLayouts($criteria, $gibbonPersonID);

    // DATA TABLE
    $table = DataTable::createPaginated('roomLayouts', $criteria);
    $table->setTitle(__('Room Layouts'));

    $table->addHeaderAction('add', __('Add'))
        ->setURL('/modules/Seating Plan/layout_add.php')
        ->displayLabel();

    $table->addHeaderAction('transfer', __('Import & Export'))
        ->setURL('/modules/Seating Plan/layout_transfer.php')
        ->setIcon('copy')
        ->displayLabel();

    // Which of the viewer's layouts were copied from something that has
    // since been improved. Fetched once for the whole page rather than per
    // row, since it is the same short list either way.
    $withUpdates = array_flip(
        array_map(
            'intval',
            $roomLayoutGateway->selectLayoutsWithUpdates($gibbonPersonID)
        )
    );

    $table->addColumn('spaceName', __('Room'));

    $table->addColumn('name', __('Layout'))
        ->format(
            function ($values) use ($withUpdates) {
                $name = htmlPrep($values['name']);

                if (!isset($withUpdates[(int) $values['seatingPlanRoomLayoutID']])) {
                    return $name;
                }

                return $name.'<br/><span class="tag message">'
                    .__('Newer version available').'</span>';
            }
        );

    $table->addColumn('size', __('Size'))
        ->notSortable()
        ->format(
            function ($values) {
                return $values['gridCols'].' &times; '.$values['gridRows'];
            }
        );

    $table->addColumn('itemCount', __('Items'))
        ->notSortable();

    $table->addColumn('owner', __('Owner'))
        ->sortable(['surname', 'preferredName'])
        ->format(
            function ($values) use ($gibbonPersonID) {
                if ($values['gibbonPersonIDOwner'] == $gibbonPersonID) {
                    return '<span class="tag dull">'.__('Me').'</span>';
                }

                return Format::name(
                    $values['title'],
                    $values['preferredName'],
                    $values['surname'],
                    'Staff',
                    false,
                    true
                );
            }
        );

    $table->addColumn('shared', __('Shared'))
        ->format(Format::using('yesNo', 'shared'));

    $table->addColumn('timestampModified', __('Updated'))
        ->format(Format::using('relativeTime', 'timestampModified'));

    $table->addActionColumn()
        ->addParam('seatingPlanRoomLayoutID')
        ->format(
            function ($values, $actions) use ($gibbonPersonID, $withUpdates) {
                $isOwner = $values['gibbonPersonIDOwner'] == $gibbonPersonID;

                if (isset($withUpdates[(int) $values['seatingPlanRoomLayoutID']])) {
                    $actions->addAction('update', __('Newer Version'))
                        ->setIcon('refresh')
                        ->setURL('/modules/Seating Plan/layout_update.php');
                }

                // The designer needs the whole viewport, so it opens as a real
                // full-screen page rather than a modal.
                $designer = Url::fromHandlerRoute('fullscreen.php')
                    ->withQueryParams(
                        [
                            'q'      => '/modules/Seating Plan/room.php',
                            'layout' => $values['seatingPlanRoomLayoutID'],
                            'mode'   => 'furniture',
                        ]
                    );

                // directLink keeps htmx out of it. Without it the link is
                // boosted, and htmx swaps in only #content-wrap - which a
                // full-screen page does not have, so nothing happens.
                $actions->addAction('edit', $isOwner ? __('Open Designer') : __('View'))
                    ->setIcon($isOwner ? 'edit' : 'search')
                    ->setURL($designer)
                    ->directLink();

                if ($isOwner) {
                    $actions->addAction('editDetails', __('Edit Details'))
                        ->setIcon('config')
                        ->setURL('/modules/Seating Plan/layout_editDetails.php');
                }

                $actions->addAction('duplicate', __('Duplicate'))
                    ->setIcon('copy')
                    ->setURL('/modules/Seating Plan/layout_duplicateProcess.php')
                    ->directLink();

                if ($isOwner) {
                    $actions->addAction('delete', __('Delete'))
                        ->setURL('/modules/Seating Plan/layout_delete.php')
                        ->modalWindow();
                }
            }
        );

    echo $table->render($layouts);
}
