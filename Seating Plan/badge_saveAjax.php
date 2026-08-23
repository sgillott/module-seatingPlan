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

// Saves the viewing teacher's own four corner badge slots - a personal
// setting, applied to every room they open, not tied to any one plan. Fires
// as soon as the config panel changes (see js/badgePanel.js), independently
// of the room's own Save button.

use Gibbon\Session\TokenHandler;
use Gibbon\Module\SeatingPlan\Domain\BadgeGateway;

include '../../gibbon.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Sends a JSON response and stops.
 *
 * @param bool   $ok      Whether the save succeeded.
 * @param string $message Text for the user.
 * @param int    $status  HTTP status code.
 *
 * @return void
 */
function badgeSaveRespond(bool $ok, string $message, int $status = 200)
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/room.php')
    == false
) {
    badgeSaveRespond(false, __('You do not have access to this action.'), 403);
}

// This endpoint is not named *Process.php, so the core CSRF check in
// gibbon.php does not cover it. Check the session token here instead.
if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    badgeSaveRespond(false, __('Your request failed due to a security error.'), 403);
}

$badgeSlotsRaw = $_POST['badgeSlots'] ?? '';
$badgeSlots = $badgeSlotsRaw !== '' ? json_decode($badgeSlotsRaw, true) : [];

if (!is_array($badgeSlots)) {
    badgeSaveRespond(false, __('Your request failed due to malformed data.'), 400);
}

// Cosmetic display configuration, not written anywhere sensitive, but still
// shape-checked: at most 4 entries, each either empty or one of the source
// keys the config panel actually offers.
foreach (array_slice($badgeSlots, 0, 4) as $slot) {
    if (!is_string($slot) || !preg_match(
        '/^(alert:[A-Za-z ]+|house|formGroup|yearGroup|targetGrade'
            . '|currentGrade|cat|)$/',
        $slot
    )) {
        badgeSaveRespond(false, __('Your request failed due to malformed data.'), 400);
    }
}

$container->get(BadgeGateway::class)->saveBadgeSlots(
    $session->get('gibbonPersonID'),
    $badgeSlots
);

badgeSaveRespond(true, __('Your request was completed successfully.'));
