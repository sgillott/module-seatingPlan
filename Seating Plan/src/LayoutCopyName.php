<?php
/**
 * @category Module
 * @package  Gibbon\Module\SeatingPlan
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */

namespace Gibbon\Module\SeatingPlan;

class LayoutCopyName
{
    public static function for($name): string
    {
        $copyLabel = function_exists('__') ? __('(Copy)') : '(Copy)';

        return mb_substr(trim((string) $name).' '.$copyLabel, 0, 40);
    }
}