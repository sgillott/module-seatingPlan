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

namespace Gibbon\Module\SeatingPlan\Domain;

use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;
use Gibbon\Domain\Traits\TableAware;

/**
 * Room layouts: the furniture arrangement of a single room.
 */
class RoomLayoutGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'seatingPlanRoomLayout';
    private static $primaryKey = 'seatingPlanRoomLayoutID';
    private static $searchableColumns = [
        'seatingPlanRoomLayout.name',
        'gibbonSpace.name',
    ];

    /**
     * Layouts the given person may open: their own, plus anything shared.
     *
     * @param QueryCriteria $criteria       Paging, sorting and filtering.
     * @param string        $gibbonPersonID The viewing user.
     *
     * @return \Gibbon\Domain\DataSet
     */
    public function queryLayouts(QueryCriteria $criteria, $gibbonPersonID)
    {
        $query = $this
            ->newQuery()
            ->from($this->getTableName())
            ->cols(
                [
                    'seatingPlanRoomLayout.seatingPlanRoomLayoutID',
                    'seatingPlanRoomLayout.name',
                    'seatingPlanRoomLayout.gridCols',
                    'seatingPlanRoomLayout.gridRows',
                    'seatingPlanRoomLayout.shared',
                    'seatingPlanRoomLayout.gibbonPersonIDOwner',
                    'seatingPlanRoomLayout.timestampModified',
                    'gibbonSpace.name AS spaceName',
                    'gibbonSpace.gibbonSpaceID',
                    'gibbonPerson.title',
                    'gibbonPerson.preferredName',
                    'gibbonPerson.surname',
                    '(SELECT COUNT(*) FROM seatingPlanFurniture
                        WHERE seatingPlanFurniture.seatingPlanRoomLayoutID
                        =seatingPlanRoomLayout.seatingPlanRoomLayoutID) AS itemCount',
                ]
            )
            ->leftJoin(
                'gibbonSpace',
                'gibbonSpace.gibbonSpaceID=seatingPlanRoomLayout.gibbonSpaceID'
            )
            ->leftJoin(
                'gibbonPerson',
                'gibbonPerson.gibbonPersonID=seatingPlanRoomLayout.gibbonPersonIDOwner'
            )
            ->where(
                '(seatingPlanRoomLayout.gibbonPersonIDOwner=:gibbonPersonID
                    OR seatingPlanRoomLayout.shared=:shared)'
            )
            ->bindValue('gibbonPersonID', $gibbonPersonID)
            ->bindValue('shared', 'Y');

        $criteria->addFilterRules(
            [
                'space' => function ($query, $gibbonSpaceID) {
                    return $query
                        ->where('seatingPlanRoomLayout.gibbonSpaceID=:filterSpace')
                        ->bindValue('filterSpace', $gibbonSpaceID);
                },
                'owner' => function ($query, $owner) use ($gibbonPersonID) {
                    if ($owner == 'mine') {
                        return $query
                            ->where(
                                'seatingPlanRoomLayout.gibbonPersonIDOwner=:filterOwner'
                            )
                            ->bindValue('filterOwner', $gibbonPersonID);
                    }

                    return $query
                        ->where('seatingPlanRoomLayout.gibbonPersonIDOwner<>:filterOwner')
                        ->bindValue('filterOwner', $gibbonPersonID);
                },
            ]
        );

        return $this->runQuery($query, $criteria);
    }

    /**
     * One layout with its room name attached.
     *
     * @param string $seatingPlanRoomLayoutID The layout.
     *
     * @return array
     */
    public function getLayoutByID($seatingPlanRoomLayoutID): array
    {
        $data = ['seatingPlanRoomLayoutID' => $seatingPlanRoomLayoutID];
        $sql = "SELECT seatingPlanRoomLayout.*, gibbonSpace.name AS spaceName
            FROM seatingPlanRoomLayout
            LEFT JOIN gibbonSpace
                ON (gibbonSpace.gibbonSpaceID=seatingPlanRoomLayout.gibbonSpaceID)
            WHERE seatingPlanRoomLayout.seatingPlanRoomLayoutID
                =:seatingPlanRoomLayoutID";

        return $this->db()->selectOne($sql, $data) ?: [];
    }

    /**
     * Starts a layout for a room that has none yet.
     *
     * A room reached from a timetabled period does not need a layout drawn
     * before anything else can be done in it: the students can be moved
     * about, the register taken and points given in a bare room. The layout
     * only has to exist at the moment something about the room itself is
     * saved, so it is made here, on that first save, rather than by anyone
     * having to draw one first.
     *
     * @param string $gibbonSpaceID       The room.
     * @param string $gibbonPersonIDOwner Whoever saved first, who owns it.
     * @param int    $gridCols            Room width in cells.
     * @param int    $gridRows            Room depth in cells.
     *
     * @return string The new layout's ID, or an empty string on failure.
     */
    public function createForSpace(
        $gibbonSpaceID,
        $gibbonPersonIDOwner,
        int $gridCols,
        int $gridRows
    ): string {
        $seatingPlanRoomLayoutID = $this->insert(
            [
                'gibbonSpaceID'       => $gibbonSpaceID,
                'name'                => __('Room Layout'),
                'gridCols'            => $gridCols,
                'gridRows'            => $gridRows,
                'gibbonPersonIDOwner' => $gibbonPersonIDOwner,
                // Shared, like the one the Add form offers by default: a room
                // nobody had drawn is a room the whole staff room can use.
                'shared'              => 'Y',
                'timestampModified'   => date('Y-m-d H:i:s'),
            ]
        );

        return (string) ($seatingPlanRoomLayoutID ?: '');
    }

    /**
     * Updates an owner layout only when the browser still has its version.
     */
    public function updateWithVersion(
        $seatingPlanRoomLayoutID,
        int $expectedVersion,
        int $gridCols,
        int $gridRows,
        string $timestampModified
    ): bool {
        return $this->db()->affectingStatement(
            'UPDATE seatingPlanRoomLayout
             SET gridCols=:gridCols,
                 gridRows=:gridRows,
                 timestampModified=:timestampModified,
                 editVersion=editVersion+1
             WHERE seatingPlanRoomLayoutID=:seatingPlanRoomLayoutID
                 AND editVersion=:expectedVersion',
            [
                'gridCols' => $gridCols,
                'gridRows' => $gridRows,
                'timestampModified' => $timestampModified,
                'seatingPlanRoomLayoutID' => $seatingPlanRoomLayoutID,
                'expectedVersion' => $expectedVersion,
            ]
        ) === 1;
    }

    /**
     * Invalidates any editing window still open on a layout, by moving its
     * version on without going through the usual optimistic check.
     *
     * Used when the furniture is replaced from outside the designer - after
     * taking a colleague's newer version - so a room left open on the old
     * arrangement is refused on its next save rather than quietly writing
     * the old furniture back over the new.
     *
     * @param string $seatingPlanRoomLayoutID The layout.
     *
     * @return bool
     */
    public function bumpEditVersion($seatingPlanRoomLayoutID): bool
    {
        return $this->db()->statement(
            'UPDATE seatingPlanRoomLayout
             SET editVersion=editVersion+1
             WHERE seatingPlanRoomLayoutID=:seatingPlanRoomLayoutID',
            ['seatingPlanRoomLayoutID' => $seatingPlanRoomLayoutID]
        );
    }

    /**
     * One layout's pending update, if the layout it was copied from has
     * moved on since.
     *
     * A layout drawn from scratch has no source and never has an update. A
     * layout whose source has since been deleted never has one either - the
     * copy is already independent, so there is simply nothing left to
     * offer.
     *
     * @param string $seatingPlanRoomLayoutID The copy.
     *
     * @return array The source's name, owner and timestamp, or empty when
     *                there is nothing to offer.
     */
    public function getPendingUpdate($seatingPlanRoomLayoutID): array
    {
        $data = ['seatingPlanRoomLayoutID' => $seatingPlanRoomLayoutID];
        $sql = "SELECT
                source.seatingPlanRoomLayoutID,
                source.name,
                source.gridCols,
                source.gridRows,
                source.timestampModified,
                gibbonPerson.title,
                gibbonPerson.preferredName,
                gibbonPerson.surname
            FROM seatingPlanRoomLayout AS mine
            JOIN seatingPlanRoomLayout AS source
                ON (source.seatingPlanRoomLayoutID
                    =mine.seatingPlanRoomLayoutIDSource)
            LEFT JOIN gibbonPerson
                ON (gibbonPerson.gibbonPersonID=source.gibbonPersonIDOwner)
            WHERE mine.seatingPlanRoomLayoutID=:seatingPlanRoomLayoutID
                AND source.timestampModified IS NOT NULL
                AND (mine.sourceTimestamp IS NULL
                    OR source.timestampModified > mine.sourceTimestamp)";

        return $this->db()->selectOne($sql, $data) ?: [];
    }

    /**
     * The IDs of a person's layouts that have an update waiting, so the
     * Room Layouts list can tag them without asking per row.
     *
     * @param string $gibbonPersonID The owner.
     *
     * @return array seatingPlanRoomLayoutID values.
     */
    public function selectLayoutsWithUpdates($gibbonPersonID): array
    {
        $data = ['gibbonPersonID' => $gibbonPersonID];
        $sql = "SELECT mine.seatingPlanRoomLayoutID
            FROM seatingPlanRoomLayout AS mine
            JOIN seatingPlanRoomLayout AS source
                ON (source.seatingPlanRoomLayoutID
                    =mine.seatingPlanRoomLayoutIDSource)
            WHERE mine.gibbonPersonIDOwner=:gibbonPersonID
                AND source.timestampModified IS NOT NULL
                AND (mine.sourceTimestamp IS NULL
                    OR source.timestampModified > mine.sourceTimestamp)";

        return $this->db()->select($sql, $data)->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Every layout a person may export, as select options labelled by room
     * and layout name.
     *
     * The same population the Room Layouts list shows them - their own,
     * plus anything shared - since exporting is only a form of reading.
     *
     * @param string $gibbonPersonID The viewing user.
     *
     * @return array Keyed by seatingPlanRoomLayoutID.
     */
    public function selectLayoutsForTransfer($gibbonPersonID): array
    {
        $data = ['gibbonPersonID' => $gibbonPersonID];
        $sql = "SELECT
                seatingPlanRoomLayout.seatingPlanRoomLayoutID,
                CONCAT(
                    COALESCE(gibbonSpace.name, '?'), ' - ',
                    seatingPlanRoomLayout.name,
                    CASE WHEN seatingPlanRoomLayout.gibbonPersonIDOwner
                        =:gibbonPersonID THEN '' ELSE ' (shared)' END
                ) AS label
            FROM seatingPlanRoomLayout
            LEFT JOIN gibbonSpace
                ON (gibbonSpace.gibbonSpaceID=seatingPlanRoomLayout.gibbonSpaceID)
            WHERE seatingPlanRoomLayout.gibbonPersonIDOwner=:gibbonPersonID
                OR seatingPlanRoomLayout.shared='Y'
            ORDER BY gibbonSpace.name, seatingPlanRoomLayout.name";

        $options = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $options[$row['seatingPlanRoomLayoutID']] = $row['label'];
        }

        return $options;
    }

    /**
     * Layouts available for a room, used when choosing one for a seating plan.
     *
     * @param string $gibbonSpaceID  The room.
     * @param string $gibbonPersonID The viewing user.
     *
     * @return \Gibbon\Contracts\Database\Result
     */
    public function selectLayoutsBySpace($gibbonSpaceID, $gibbonPersonID)
    {
        $data = [
            'gibbonSpaceID'  => $gibbonSpaceID,
            'gibbonPersonID' => $gibbonPersonID,
        ];
        // The viewer's own layouts come first, so opening a room lands on
        // their own arrangement rather than a colleague's. Failing that,
        // the most recently updated one anyone has drawn for the room: a
        // room should open on the freshest picture of itself, not on
        // whichever layout happens to sort first by name.
        $sql = "SELECT seatingPlanRoomLayoutID AS value, name
            FROM seatingPlanRoomLayout
            WHERE gibbonSpaceID=:gibbonSpaceID
                AND (gibbonPersonIDOwner=:gibbonPersonID OR shared='Y')
            ORDER BY gibbonPersonIDOwner=:gibbonPersonID DESC,
                timestampModified DESC, seatingPlanRoomLayoutID DESC";

        return $this->db()->select($sql, $data);
    }
}
