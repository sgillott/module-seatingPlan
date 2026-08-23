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

// Staff Dashboard hook. Included from Gibbon\UI\Dashboard\StaffDashboard's own
// renderDashboard() method as `$hookOutput = include $include;` - its return
// value becomes the tab content, so this file must return HTML, never echo
// it. $guid, $connection2 and $gibbonPersonID already exist as local
// variables at the point of inclusion (that method sets them up for exactly
// this purpose); $container does not, since that method never assigns it
// locally, so it is pulled in explicitly here.

use Gibbon\Services\ModuleLoader;
use Gibbon\Module\SeatingPlan\MyLessonsTable;
use Gibbon\Module\SeatingPlan\Domain\TimetableSlotGateway;

global $container;

// The Staff Dashboard is a different "current module" from Seating Plan's
// own pages, and Gibbon only auto-registers the PSR-4 namespace for
// whichever module the session says is current (see gibbon.php) - so
// Gibbon\Module\SeatingPlan\* is not autoloadable here unless this is done
// explicitly, even though it works without it when room.php or
// seatingPlans.php is opened directly.
$container->get(ModuleLoader::class)->registerModuleNamespace('Seating Plan');

if (
    !isActionAccessible($guid, $connection2, '/modules/Seating Plan/seatingPlans.php')
) {
    return '';
}

// The same day My Lessons itself would open on: today, unless today has no
// lessons, in which case the most recent day that did. Without this the
// dashboard shows an empty table every weekend and holiday while My Lessons
// shows Friday's - the same page, disagreeing with itself. The table puts
// the date in its own title, so the reader is never left guessing which day
// they are looking at.
$date = $container->get(TimetableSlotGateway::class)
    ->selectDefaultDate($gibbonPersonID, date('Y-m-d'));

return $container->get(MyLessonsTable::class)
    ->renderForDate($gibbonPersonID, $date);
