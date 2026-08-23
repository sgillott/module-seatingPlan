/**
 * Seating Plan: furniture mode.
 *
 * Draws the room itself - desks, chairs, benching, the board. Everything about
 * moving and selecting lives in the shell; this file supplies the catalogue,
 * the artwork, and the rules for which way a piece should face.
 */
(function () {
    'use strict';

    var room = null;
    var config = null;
    var items = [];
    var STEP = 10;

    function spec(type) {
        return config.catalogue[type] || { wide: STEP, high: STEP, resizable: false };
    }

    /* ------------------------------------------------------ facing a desk */

    // Rotation 0 puts the chair's back at the top, so the student faces down.
    // Each quarter turn is clockwise, giving: 0 down, 1 left, 2 up, 3 right.
    var FACINGS = [
        { rotation: 0, dx: 0, dy: 1 },
        { rotation: 1, dx: -1, dy: 0 },
        { rotation: 2, dx: 0, dy: -1 },
        { rotation: 3, dx: 1, dy: 0 }
    ];

    /**
     * Scores each of the four sides by how much of a given kind of furniture
     * sits against it, looking one item-width out.
     */
    function scoreSides(item, matches) {
        var box = room.rectOf(item);

        return FACINGS.map(function (facing) {
            var probe = {
                x: box.x + facing.dx * box.w,
                y: box.y + facing.dy * box.h,
                w: box.w,
                h: box.h
            };

            var score = items.reduce(function (total, other) {
                if (other === item || !matches(other)) {
                    return total;
                }
                return total + room.overlapArea(probe, room.rectOf(other));
            }, 0);

            return { rotation: facing.rotation, facing: facing, score: score };
        });
    }

    function bestSide(sides) {
        return sides.reduce(function (best, side) {
            if (side.score <= 0) {
                return best;
            }
            return best === null || side.score > best.score ? side : best;
        }, null);
    }

    /**
     * Which side of a piece is closest to a wall, counting both the room's own
     * edges and any wall piece drawn inside it.
     */
    function nearestWall(item) {
        var box = room.rectOf(item);

        var edges = [
            { facing: FACINGS[2], distance: box.y },
            { facing: FACINGS[0], distance: room.limitY() - (box.y + box.h) },
            { facing: FACINGS[1], distance: box.x },
            { facing: FACINGS[3], distance: room.limitX() - (box.x + box.w) }
        ];

        var closest = edges.reduce(function (best, edge) {
            return best === null || edge.distance < best.distance ? edge : best;
        }, null);

        // A wall piece hard against the item beats a distant room edge.
        var drawn = bestSide(scoreSides(item, function (other) {
            return spec(other.type).wall;
        }));

        if (drawn !== null) {
            return drawn.facing;
        }

        // Only treat the room edge as a wall when the piece is actually near it.
        return closest !== null && closest.distance <= STEP ? closest.facing : null;
    }

    /**
     * Turns a piece to face what it should: a chair faces its desk, a computer
     * faces the chair using it, or turns its back on the wall behind it.
     *
     * A piece with nothing relevant nearby is left exactly as the user set it.
     *
     * @return bool Whether the rotation changed.
     */
    function faceItem(item) {
        var rule = spec(item.type);

        if (!rule.facesToward) {
            return false;
        }

        var target = bestSide(scoreSides(item, function (other) {
            return spec(other.type)[rule.facesToward];
        }));

        var rotation = null;

        if (target !== null) {
            rotation = target.rotation;
        } else if (rule.facesFromWalls) {
            var wall = nearestWall(item);

            if (wall !== null) {
                // Face the opposite way to the wall.
                var away = FACINGS.filter(function (facing) {
                    return facing.dx === -wall.dx && facing.dy === -wall.dy;
                })[0];

                rotation = away ? away.rotation : null;
            }
        }

        if (rotation === null || item.rotation === rotation) {
            return false;
        }

        item.rotation = rotation;

        return true;
    }

    /**
     * Turns every piece that has a facing rule, in one go.
     */
    function faceEverything() {
        var turned = items.filter(faceItem).length;

        if (turned === 0) {
            room.setStatus(room.text('chairsAlreadyFacing'));
            return;
        }

        room.render();
        room.markDirty();
        room.setStatus(room.text('chairsTurned').replace('{count}', turned), 'ok');
    }

    /* ----------------------------------------------------------- the mode */

    function addItem(type) {
        var definition = spec(type);
        var spot = room.firstFreeSpot(definition.wide, definition.high);

        items.push({
            type: type,
            posX: spot.posX,
            posY: spot.posY,
            rotation: 0,
            flipped: false,
            sizeX: definition.wide,
            sizeY: definition.high
        });

        room.markDirty();
        room.render();
        room.select(items.length - 1);
    }

    window.SeatingPlanRoom.register({
        id: 'furniture',

        load: function (shell, payload) {
            room = shell;
            config = payload;
            STEP = shell.STEP();

            items = (payload.items || []).map(function (item) {
                var definition = config.catalogue[item.type] || {};

                // Only a resizable piece keeps the size it was saved with.
                // Everything else takes its size from the catalogue, exactly as
                // the server does when saving, so a piece whose size changed in
                // a later release is not drawn in a box left over from the old
                // one.
                var fixed = definition.resizable !== true;

                return {
                    type: item.type,
                    posX: parseInt(item.posX, 10),
                    posY: parseInt(item.posY, 10),
                    rotation: parseInt(item.rotation, 10) || 0,
                    flipped: item.flipped === 'Y',
                    sizeX: fixed
                        ? (definition.wide || STEP)
                        : (parseInt(item.sizeX, 10) || STEP),
                    sizeY: fixed
                        ? (definition.high || STEP)
                        : (parseInt(item.sizeY, 10) || STEP)
                };
            });
        },

        getItems: function () {
            return items;
        },

        specOf: function (item) {
            return spec(item.type);
        },

        buildArt: function (item) {
            // The art keeps the item's own proportions and turns inside the
            // box. Both are centred, so a quarter turn lands exactly on the
            // grid.
            return window.SeatingPlanFurnitureArt.buildArt(item, spec(item.type));
        },

        positionArt: function (art, item) {
            if (art) {
                window.SeatingPlanFurnitureArt.applyArt(art, item);
            }
        },

        onDrop: function (indices) {
            // Anything just dragged turns to face what it landed against.
            // Clicking a piece still turns it by hand.
            indices.forEach(function (index) {
                if (faceItem(items[index])) {
                    room.refreshItem(index);
                }
            });
        },

        getSavePayload: function () {
            return {
                seatingPlanRoomLayoutID: config.layoutID,
                items: JSON.stringify(items.map(function (item) {
                    return {
                        type: item.type,
                        posX: item.posX,
                        posY: item.posY,
                        rotation: item.rotation,
                        flipped: item.flipped ? 'Y' : 'N',
                        sizeX: item.sizeX,
                        sizeY: item.sizeY
                    };
                }))
            };
        },

        mount: function () {
            var palette = document.getElementById('spPalette');

            if (palette) {
                palette.addEventListener('click', function (event) {
                    var tool = event.target.closest('.sp-tool');
                    if (tool) {
                        addItem(tool.dataset.type);
                    }
                });
            }

            var faceButton = document.getElementById('spFaceChairs');
            if (faceButton) {
                faceButton.addEventListener('click', faceEverything);
            }

            var clearButton = document.getElementById('spClear');
            if (clearButton) {
                clearButton.addEventListener('click', function () {
                    if (items.length === 0) {
                        return;
                    }
                    if (!window.confirm(room.text('confirmClear'))) {
                        return;
                    }
                    items.length = 0;
                    room.select(-1);
                    room.markDirty();
                    room.render();
                });
            }
        }
    });
}());
