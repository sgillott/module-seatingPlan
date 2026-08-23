<?php
/**
 * Gibbon, Flexible & Open School System
 * Copyright (C) 2010, Ross Parker
 *
 * @category Module
 * @package  Gibbon\Module\SeatingPlan
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */

namespace Gibbon\Module\SeatingPlan;

use InvalidArgumentException;

/**
 * Converts a posted or stored class set into one stable identity string.
 */
class ClassListNormalizer
{
    /**
     * @param string|array $value Comma-separated or array class IDs.
     * @return string Canonical ascending, comma-separated IDs.
     * @throws InvalidArgumentException When an ID is malformed or empty.
     */
    public static function normalize($value): string
    {
        $parts = is_array($value)
            ? $value
            : explode(',', trim((string) $value));

        $ids = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || !preg_match('/^\d+$/D', $part)) {
                throw new InvalidArgumentException('Class list contains an invalid class ID.');
            }

            $part = ltrim($part, '0');
            if ($part === '') {
                throw new InvalidArgumentException('Class list contains an invalid class ID.');
            }

            $ids[$part] = true;
        }

        if (empty($ids)) {
            throw new InvalidArgumentException('Class list cannot be empty.');
        }

        $ids = array_keys($ids);
        usort($ids, function ($a, $b) {
            return (int) $a <=> (int) $b;
        });

        return implode(',', $ids);
    }
}