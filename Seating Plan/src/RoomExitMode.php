<?php
/**
 * Gibbon, Flexible & Open School System
 * @category Module
 * @package  Gibbon\Module\SeatingPlan
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */

namespace Gibbon\Module\SeatingPlan;

use Gibbon\Contracts\Services\Session;
use Gibbon\Module\SeatingPlan\Domain\StudentRosterGateway;
use Gibbon\Module\SeatingPlan\Domain\SeatingPlanGateway;
use Gibbon\Module\SeatingPlan\Domain\RoomExitGateway;

/** Builds the room payload for room-exit mode. */
class RoomExitMode
{
    private $rosterGateway;
    private $planGateway;
    private $exitGateway;
    private $session;

    public function __construct(
        StudentRosterGateway $rosterGateway,
        SeatingPlanGateway $planGateway,
        RoomExitGateway $exitGateway,
        Session $session
    ) {
        $this->rosterGateway = $rosterGateway;
        $this->planGateway = $planGateway;
        $this->exitGateway = $exitGateway;
        $this->session = $session;
    }

    public function buildPayload(RoomContext $context): array
    {
        $classes = $context->getClasses();
        $courseClassIDs = array_column($classes, 'gibbonCourseClassID');
        $roster = $this->rosterGateway->selectRoster(
            $courseClassIDs,
            [],
            $context->getDate(),
            $this->session->get('gibbonSchoolYearID')
        );

        $plan = $context->getLayoutID() !== ''
            ? $this->planGateway->getPlanByLayoutAndClasses(
                $context->getLayoutID(),
                $context->getClassList()
            )
            : [];

        return [
            'roster'    => StudentRosterPresenter::build($roster),
            'seats'     => !empty($plan)
                ? $this->planGateway->selectSeatsByPlan($plan['seatingPlanPlanID'])
                : [],
            'openExits' => $this->exitGateway->selectOpenExitsByRoom(
                $context->getSpaceID()
            ),
        ];
    }
}