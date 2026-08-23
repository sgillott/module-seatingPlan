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

// Lesson Planner hook. Included from modules/Planner/planner_view_full.php at
// the top level of that script, with a bare `include $include;` - unlike the
// Staff Dashboard hook, its output must be echoed, not returned. $values
// (the lesson: date, timeStart, timeEnd), $gibbonCourseClassID and $session
// are already in scope at the point of inclusion, since this file is
// included directly into that page's own script, not a class method.
// $container is a genuine global set once by gibbon.php's own bootstrap, so
// it needs no special handling here either.

use Gibbon\Services\ModuleLoader;
use Gibbon\Http\Url;
use Gibbon\Domain\Timetable\TimetableDayDateGateway;
use Gibbon\Module\SeatingPlan\RoomContextResolver;

if (
    !isActionAccessible($guid, $connection2, '/modules/Seating Plan/seatingPlans.php')
) {
    return;
}

// Planner is a different "current module" from Seating Plan's own pages,
// and Gibbon only auto-registers the PSR-4 namespace for whichever module
// the session says is current (see gibbon.php) - so Gibbon\Module\
// SeatingPlan\* is not autoloadable here unless this is done explicitly,
// even though it works without it when room.php is opened directly.
$container->get(ModuleLoader::class)->registerModuleNamespace('Seating Plan');

$ttPeriod = $container->get(TimetableDayDateGateway::class)
    ->getTimetabledPeriodByClassAndTime(
        $gibbonCourseClassID,
        $values['date'],
        $values['timeStart'],
        $values['timeEnd']
    );

// No timetabled placement for this exact lesson (a manually logged entry
// with no real slot, for instance) - nothing to seat, so nothing shown.
if (empty($ttPeriod['gibbonTTDayRowClassID'])) {
    return;
}

$context = $container->get(RoomContextResolver::class)->fromPeriod(
    $ttPeriod['gibbonTTDayRowClassID'],
    $values['date'],
    $session->get('gibbonPersonID')
);

// No room on the timetable for this period either - same quiet no-op.
if ($context === null) {
    return;
}

$room = Url::fromHandlerRoute('fullscreen.php')->withQueryParams([
    'q'      => '/modules/Seating Plan/room.php',
    'period' => $ttPeriod['gibbonTTDayRowClassID'],
    'date'   => $values['date'],
]);
?>
<div class="linkTop">
    <a href="<?php echo htmlspecialchars((string) $room, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo icon('solid', 'users', 'inline-block size-5 -mt-1 mr-1'); ?>
        <?php echo __('Open Seating Plan'); ?>
    </a>
</div>
