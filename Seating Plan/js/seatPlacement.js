/**
 * Seating Plan: where each student stands in the room.
 *
 * Every mode that puts students in the room asks this one question, so they
 * all ask it here rather than each answering it slightly differently.
 *
 * A saved plan says where somebody sits. Anybody the plan does not mention -
 * a room nobody has arranged yet, or a student who joined the class after it
 * was arranged - is laid out in the first free square, reading order, so they
 * are on screen and can be moved, marked, picked and pointed like everyone
 * else. Dropping them for want of a seat would hide a real student from their
 * own teacher.
 *
 * Where the room has chairs, only a saved position that is actually on one
 * counts: furniture may have been redrawn under an old plan, and a student
 * left at a stale position would look seated when they are not. A room with
 * no chairs has no such anchor, so every saved position is taken as it
 * stands - see SeatingPlanGateway::validateSeats(), which draws the same
 * distinction server side.
 */
(function (window) {
    'use strict';

    /**
     * Whether two one-cell tiles at these positions would touch.
     */
    function overlaps(a, b, step) {
        return a.posX < b.posX + step
            && a.posX + step > b.posX
            && a.posY < b.posY + step
            && a.posY + step > b.posY;
    }

    window.SeatingPlanSeatPlacement = {
        /**
         * @param object options roster, seats, chairs (an array of "x,y"
         *                      keys, or null/empty for a room with none),
         *                      gridCols, gridRows, step.
         *
         * @return object positions, keyed by gibbonPersonID, each with posX,
         *                posY and seated (whether it came from the saved
         *                plan); plus placed, how many had to be laid out
         *                because the plan did not place them.
         */
        resolve: function (options) {
            var roster = options.roster || [];
            var chairs = options.chairs || [];
            var step = options.step;
            var limitX = (options.gridCols - 1) * step;
            var limitY = (options.gridRows - 1) * step;
            var positions = {};
            var taken = [];
            var placed = 0;
            var saved = {};

            (options.seats || []).forEach(function (seat) {
                var x = parseInt(seat.posX, 10);
                var y = parseInt(seat.posY, 10);

                if (chairs.length > 0 && chairs.indexOf(x + ',' + y) < 0) {
                    return;
                }

                saved[seat.gibbonPersonID] = { posX: x, posY: y };
            });

            roster.forEach(function (student) {
                var spot = saved[student.gibbonPersonID];

                if (!spot) {
                    return;
                }

                positions[student.gibbonPersonID] = {
                    posX: spot.posX,
                    posY: spot.posY,
                    seated: true
                };
                taken.push(spot);
            });

            // Reading order from wherever the last one landed: the scan only
            // ever moves forward, so laying out a whole class stays cheap.
            var cursorX = 0;
            var cursorY = 0;

            roster.forEach(function (student) {
                if (positions[student.gibbonPersonID]) {
                    return;
                }

                var spot = { posX: cursorX, posY: cursorY };

                while (
                    spot.posY <= limitY
                    && taken.some(function (other) {
                        return overlaps(spot, other, step);
                    })
                ) {
                    spot = {
                        posX: spot.posX + step,
                        posY: spot.posY
                    };

                    if (spot.posX > limitX) {
                        spot = { posX: 0, posY: spot.posY + step };
                    }
                }

                // A room too full to lay anybody else out puts them in the
                // corner: on top of somebody, but on screen and draggable,
                // which is better than nowhere at all.
                if (spot.posY > limitY) {
                    spot = { posX: 0, posY: 0 };
                }

                positions[student.gibbonPersonID] = {
                    posX: spot.posX,
                    posY: spot.posY,
                    seated: false
                };
                taken.push(spot);
                placed++;

                cursorX = spot.posX + step;
                cursorY = spot.posY;

                if (cursorX > limitX) {
                    cursorX = 0;
                    cursorY = cursorY + step;
                }
            });

            return { positions: positions, placed: placed };
        }
    };
}(window));
