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

use Gibbon\Http\Url;
use Gibbon\Module\SeatingPlan\LayoutTransfer;

require __DIR__.'/../../gibbon.php';

$URL = Url::fromModuleRoute('Seating Plan', 'layout_transfer');

if (
    isActionAccessible(
        $guid,
        $connection2,
        '/modules/Seating Plan/layout_transfer.php'
    ) == false
) {
    $URL = $URL->withReturn('error0');
    header("Location: {$URL}");
    exit;
}

$action = $_GET['action'] ?? '';
$gibbonPersonID = $session->get('gibbonPersonID');
$transfer = $container->get(LayoutTransfer::class);

/* ------------------------------------------------------------- export */

if ($action === 'export') {
    $layoutIDs = $_POST['seatingPlanRoomLayoutIDList'] ?? [];

    if (!is_array($layoutIDs) || empty($layoutIDs)) {
        $URL = $URL->withReturn('error1');
        header("Location: {$URL}");
        exit;
    }

    // The module's own version, for support: a file that will not import
    // is much easier to explain when it says where it came from.
    include __DIR__.'/version.php';

    $payload = $transfer->export(
        $layoutIDs,
        $gibbonPersonID,
        $moduleVersion ?? ''
    );

    if (empty($payload['layouts'])) {
        $URL = $URL->withReturn('error1');
        header("Location: {$URL}");
        exit;
    }

    $filename = 'seating-plan-layouts-'.date('Ymd-His').'.json';

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ------------------------------------------------------------- import */

if ($action !== 'import') {
    $URL = $URL->withReturn('error1');
    header("Location: {$URL}");
    exit;
}

$gibbonSpaceID = $_POST['gibbonSpaceID'] ?? '';
$name = $_POST['name'] ?? '';
$shared = ($_POST['shared'] ?? 'Y') === 'N' ? 'N' : 'Y';

if ($gibbonSpaceID === '' || empty($_FILES['layoutFile']['tmp_name'])) {
    $URL = $URL->withReturn('error1');
    header("Location: {$URL}");
    exit;
}

// The room has to be a real one, so a hand-built post cannot file a layout
// against a space that does not exist.
$spaceExists = $pdo->selectOne(
    'SELECT COUNT(*) FROM gibbonSpace WHERE gibbonSpaceID=:gibbonSpaceID',
    ['gibbonSpaceID' => $gibbonSpaceID]
);

if (empty($spaceExists)) {
    $URL = $URL->withReturn('error1');
    header("Location: {$URL}");
    exit;
}

$raw = file_get_contents($_FILES['layoutFile']['tmp_name']);

if ($raw === false || trim((string) $raw) === '') {
    $URL = $URL->withReturn('error1');
    header("Location: {$URL}");
    exit;
}

try {
    $layouts = $transfer->parse($raw);
} catch (\InvalidArgumentException $e) {
    // The reason matters here - "not a layout file" and "drawn for a bigger
    // room" need different things done about them - so it is carried back
    // to the page rather than flattened into a generic failure.
    $URL = $URL->withQueryParam('importError', $e->getMessage())
        ->withReturn('error1');
    header("Location: {$URL}");
    exit;
}

// A name override only makes sense for a file holding a single layout;
// several layouts cannot all be called the same thing.
if (count($layouts) > 1) {
    $name = '';
}

$imported = 0;

try {
    foreach ($layouts as $layout) {
        $transfer->import($layout, $gibbonSpaceID, $gibbonPersonID, $name, $shared);
        ++$imported;
    }
} catch (\InvalidArgumentException $e) {
    $URL = $URL->withQueryParam('importError', $e->getMessage())
        ->withReturn($imported > 0 ? 'warning1' : 'error1');
    header("Location: {$URL}");
    exit;
} catch (\Throwable $e) {
    $URL = $URL->withReturn('error2');
    header("Location: {$URL}");
    exit;
}

$URL = $URL->withReturn('success0');
header("Location: {$URL}");
