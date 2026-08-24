/**
 * Seating Plan: the name picker.
 *
 * A cold-call tool that runs continuously, not on demand: the moment the
 * mode loads, a yellow highlight starts cycling across every seated
 * student, forever, until a tap stops it. That tap reads which third of
 * the room it landed in (left = low ability, middle = mid, right = high,
 * by CAT4 Mean SAS), then narrows the flicker to that band and lets it
 * slow down step by step - like a wheel losing momentum - before landing
 * on the chosen face, held large on screen. The next tap, anywhere, clears
 * that pick and starts the cycling again - so the rhythm is: running, tap
 * to choose (with a moment of slowing drama), held, tap to resume running.
 * Leaving the mode is a full page navigation (the mode strip's links are
 * plain URLs), which tears down this script and its timer along with it -
 * nothing to stop by hand.
 *
 * Nothing is read from or written to the shell's own drag/select/save
 * machinery - config.canEdit is deliberately false for this mode (set by
 * room.php) so room.js's entire pointer pipeline stays inert, and this
 * file wires its own single click listener directly on the room element
 * instead.
 */
(function () {
    'use strict';

    var room = null;
    var config = null;
    var roomEl = null;
    var STEP = 10;

    // Every seated student, positioned exactly where seating mode left
    // them. Never mutated after load - nothing here moves or is saved.
    var tiles = [];

    // Three ability pools, built once from CAT4 Mean SAS. A student with no
    // score is ranked at the median rather than excluded outright. When
    // there isn't enough real data to make three groups mean anything,
    // every pool is simply the same full list - genuinely random
    // regardless of which third was tapped.
    var pools = { low: [], mid: [], high: [] };

    // Who is still to be asked in the current round, per band. A pick is
    // drawn out of the bag rather than off the top of a shuffle, and the
    // bag refills only once it is empty - so everybody in a band gets
    // asked before anybody is asked twice. Plain random picking is what a
    // teacher notices immediately, because it happily asks the same child
    // four times running while somebody else is never asked at all.
    var bags = { low: [], mid: [], high: [] };

    // The last student actually chosen, so a new round never opens with
    // whoever closed the previous one.
    var lastPicked = null;

    var state = 'cycling'; // 'cycling' | 'settling' | 'showing'
    var flickerTimer = null;
    var FLICKER_MS = 200;

    // Past about half a second between hops the suspense has run its course.
    var SLOWEST_MS = 520;

    // The bar across the top of the room, and the canvas the confetti is
    // thrown onto. Both live inside the picker's own layer.
    var progressEl = null;
    var confettiEl = null;
    var throwConfetti = null;

    /**
     * How long the highlight takes to slow to a stop, hop by hop.
     *
     * Each wait is a little longer than the last until the next one would
     * be slower than SLOWEST_MS, at which point the choice is held. Worked
     * out up front rather than as it goes, so the progress bar can be told
     * exactly how long it has to fill - the bar and the flicker cannot
     * drift apart if they are reading from the same list.
     *
     * @return array The wait before each hop, in milliseconds.
     */
    function settleDelays() {
        var delays = [FLICKER_MS];
        var next = Math.round(FLICKER_MS * 1.35);

        while (next < SLOWEST_MS) {
            delays.push(next);
            next = Math.round(next * 1.35);
        }

        return delays;
    }

    function totalOf(delays) {
        return delays.reduce(function (sum, delay) {
            return sum + delay;
        }, 0);
    }

    /* -------------------------------------------------------------- setup */

    function buildBackdrop() {
        window.SeatingPlanFurnitureBackdrop.build(
            config,
            STEP,
            document.getElementById('spRoom')
        );
    }

    /**
     * A static tile for every student in the room, in its own layer.
     *
     * The layer is not itself .sp-item-classed, so room.js's render()
     * (which clears only direct .sp-item children of #spRoom) leaves
     * everything here alone.
     *
     * Anyone the seating plan does not place - a room nobody has arranged,
     * or a student who joined since - is laid out in rows instead, because a
     * name picker that quietly leaves some of the class out is worse than no
     * picker at all.
     */
    function buildTiles() {
        var layer = document.createElement('div');
        layer.className = 'sp-picker-layer';

        var placement = window.SeatingPlanSeatPlacement.resolve({
            roster: config.roster,
            seats: config.seats,
            chairs: null,
            gridCols: config.gridCols,
            gridRows: config.gridRows,
            step: STEP
        });

        tiles = [];

        (config.roster || []).forEach(function (student) {
            var spot = placement.positions[student.gibbonPersonID];

            var box = document.createElement('div');
            box.className = 'sp-item sp-picker-tile';
            box.dataset.layer = 'seat';
            box.style.setProperty('--c', spot.posX);
            box.style.setProperty('--r', spot.posY);
            box.style.setProperty('--w', STEP);
            box.style.setProperty('--h', STEP);
            box.appendChild(window.SeatingPlanStudentTile.build(student));

            layer.appendChild(box);

            tiles.push({
                gibbonPersonID: student.gibbonPersonID,
                element: box
            });
        });

        confettiEl = document.createElement('canvas');
        confettiEl.className = 'sp-picker-confetti';
        layer.appendChild(confettiEl);

        progressEl = document.createElement('div');
        progressEl.className = 'sp-picker-progress';
        layer.appendChild(progressEl);

        roomEl.appendChild(layer);
    }

    /* ------------------------------------------------------- the waiting */

    /**
     * Starts the bar filling across the top of the room, green to red, over
     * exactly as long as the highlight will take to stop.
     *
     * The fill is a new element every time: the animation plays on whatever
     * element carries it, and re-running one already on screen means
     * fighting the browser to restart it.
     *
     * @param int duration How long the slow-down will take, in ms.
     *
     * @return void
     */
    function startProgress(duration) {
        if (!progressEl) {
            return;
        }

        progressEl.innerHTML = '';

        var fill = document.createElement('div');
        fill.className = 'sp-picker-progress-fill';
        fill.style.setProperty('--sp-picker-ms', duration + 'ms');
        progressEl.appendChild(fill);
    }

    /**
     * Clears the bar. Called when the choice is made and when the highlight
     * goes back to roaming, so the bar is only ever on screen during the
     * wait it describes.
     */
    function clearProgress() {
        if (progressEl) {
            progressEl.innerHTML = '';
        }
    }

    /* ------------------------------------------------------- the confetti */

    /**
     * Throws confetti from behind the chosen student.
     *
     * canvas-confetti is loaded from a CDN by room.php, so it may simply
     * not be there - an install with no route out to the internet, or a
     * blocked request. Nothing here is load-bearing, so its absence is not
     * worth a word to the teacher: the pick still works, it is just quieter.
     *
     * @param object tile The chosen student's tile.
     *
     * @return void
     */
    function celebrate(tile) {
        if (typeof window.confetti !== 'function' || !confettiEl) {
            return;
        }

        if (
            window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        if (throwConfetti === null) {
            // No worker: it would hand the canvas to an OffscreenCanvas and
            // that is a lot of moving parts for ninety paper squares.
            throwConfetti = window.confetti.create(confettiEl, {
                resize: true
            });
        }

        // Measured against the canvas, not the room. The library multiplies
        // the origin by the canvas's own width and height, so the canvas is
        // the only box the fraction can be a fraction of - even though the
        // stylesheet sizes it to cover the room exactly.
        var canvas = confettiEl.getBoundingClientRect();
        var seat = tile.element.getBoundingClientRect();

        if (!canvas.width || !canvas.height) {
            return;
        }

        // The middle of the chosen tile, so the confetti appears to come out
        // from behind them rather than from a corner of the room.
        var origin = {
            x: (seat.left + seat.width / 2 - canvas.left) / canvas.width,
            y: (seat.top + seat.height / 2 - canvas.top) / canvas.height
        };

        throwConfetti({
            particleCount: 90,
            spread: 100,
            startVelocity: 32,
            gravity: 0.9,
            scalar: 0.9,
            ticks: 140,
            origin: origin
        });
    }

    /**
     * Splits the seated students into three ability bands by CAT4 Mean SAS.
     *
     * A student with no score is folded into the middle band rather than
     * left out - not being tested is not a reason never to be asked. If
     * there are fewer than three real scores among the seated students, the
     * bands are abandoned altogether and all three become the same full
     * list, so a tap anywhere is simply random. That is the case in most
     * schools, and it is better than pretending a ranking exists.
     */
    /**
     * One student's place in the ability order.
     *
     * Anybody without a score sits at the median of those who have one, so
     * they rank in the middle of the room rather than at one end of it.
     */
    function abilityOf(index, scores, median) {
        var score = scores[tiles[index].gibbonPersonID];
        var value = score ? parseFloat(score.value) : NaN;

        return isNaN(value) ? median : value;
    }

    function buildPools() {
        var scores = config.catScores || {};
        var everyone = allIndexes();
        var values = [];

        everyone.forEach(function (index) {
            var score = scores[tiles[index].gibbonPersonID];
            var value = score ? parseFloat(score.value) : NaN;

            if (!isNaN(value)) {
                values.push(value);
            }
        });

        // Too little to rank on. Three bands drawn from two scores would be
        // a ranking of nobody dressed up as one, so the thirds are dropped
        // and a tap anywhere draws from the whole room.
        if (values.length < 3) {
            pools = { low: everyone, mid: everyone, high: everyone };
            resetBags();

            return;
        }

        values.sort(function (a, b) {
            return a - b;
        });

        var median = values[Math.floor(values.length / 2)];

        // The whole room is ranked, not just the tested part of it. Taking
        // thirds of the scored students alone would make a band as small as
        // one person whenever CAT coverage is patchy - and a band of one
        // returns the same child every single time it is tapped.
        var ranked = everyone.slice().sort(function (a, b) {
            return abilityOf(a, scores, median) - abilityOf(b, scores, median)
                || a - b;
        });

        var cut = Math.floor(ranked.length / 3);

        pools = {
            low: ranked.slice(0, cut),
            mid: ranked.slice(cut, ranked.length - cut),
            high: ranked.slice(ranked.length - cut)
        };

        // A band that came out empty - a room of one or two - would make a
        // tap do nothing, which reads as the tool being broken.
        ['low', 'mid', 'high'].forEach(function (band) {
            if (!pools[band].length) {
                pools[band] = everyone;
            }
        });

        resetBags();
    }

    function resetBags() {
        bags = { low: [], mid: [], high: [] };
    }

    /**
     * Chooses the student to land on, drawing without replacement.
     *
     * The band's bag empties one student at a time and only refills once
     * everybody in it has had a turn, so a class is worked through rather
     * than sampled. Across the refill, whoever was asked last is passed
     * over if there is anybody else to ask - otherwise the last child of
     * one round and the first of the next can be the same one.
     *
     * @return int The tile index to settle on, or -1 when nobody can be.
     */
    function pickFrom(band) {
        var pool = (pools[band] && pools[band].length)
            ? pools[band]
            : allIndexes();

        if (!pool.length) {
            return -1;
        }

        if (!bags[band] || !bags[band].length) {
            bags[band] = pool.slice();
        }

        var choices = bags[band];

        if (choices.length > 1 && lastPicked !== null) {
            var withoutLast = choices.filter(function (index) {
                return index !== lastPicked;
            });

            if (withoutLast.length) {
                choices = withoutLast;
            }
        }

        var chosen = choices[Math.floor(Math.random() * choices.length)];

        bags[band] = bags[band].filter(function (index) {
            return index !== chosen;
        });
        lastPicked = chosen;

        return chosen;
    }

    /* ------------------------------------------------------- the flicker */

    function clearMarks() {
        tiles.forEach(function (tile) {
            tile.element.classList.remove(
                'sp-picker-flicker',
                'sp-picker-dim',
                'sp-picker-spotlight'
            );
        });
    }

    /**
     * Puts the yellow wash on one tile and takes it off every other.
     */
    function highlight(index) {
        tiles.forEach(function (tile, at) {
            tile.element.classList.toggle('sp-picker-flicker', at === index);
        });
    }

    function randomFrom(pool) {
        return pool[Math.floor(Math.random() * pool.length)];
    }

    function stopCycling() {
        if (flickerTimer !== null) {
            window.clearTimeout(flickerTimer);
            window.clearInterval(flickerTimer);
            flickerTimer = null;
        }
    }

    /**
     * The resting state: the highlight hops across everybody, forever,
     * until somebody taps.
     */
    function startCycling() {
        stopCycling();
        clearMarks();

        if (!tiles.length) {
            return;
        }

        state = 'cycling';
        clearProgress();
        room.setStatus(room.text('pickerTapToChoose'));

        // While nothing has been chosen the highlight roams the whole room,
        // not one band: the bands only mean something once a tap has said
        // which one to draw from.
        var everyone = allIndexes();

        flickerTimer = window.setInterval(function () {
            highlight(randomFrom(everyone));
        }, FLICKER_MS);
    }

    function allIndexes() {
        return tiles.map(function (unused, index) {
            return index;
        });
    }

    /**
     * Narrows to one band and lets the flicker slow to a stop, like a wheel
     * losing momentum, before holding the face it lands on.
     */
    function chooseFromThird(band) {
        stopCycling();

        var pool = (pools[band] && pools[band].length)
            ? pools[band]
            : allIndexes();

        if (!pool.length) {
            return;
        }

        // Decided now, once, before any of the animation runs. The flicker
        // below is decoration: if it chose as it went, every frame would
        // take a name out of the bag and four fifths of the class would be
        // skipped over per tap.
        var winner = pickFrom(band);

        if (winner < 0) {
            return;
        }

        state = 'settling';
        room.setStatus('');

        // Everyone outside the band fades back, so it is visible that the
        // choice is being made from a group rather than from the room.
        tiles.forEach(function (tile, index) {
            tile.element.classList.toggle(
                'sp-picker-dim',
                pool.indexOf(index) < 0
            );
        });

        // Each hop is a little slower than the last, like a wheel losing
        // momentum. The bar across the top of the room fills over exactly
        // the same stretch of time, so the wait reads as a wait rather than
        // as the room having stopped responding.
        var delays = settleDelays();
        var step = 0;

        startProgress(totalOf(delays));

        function tick() {
            highlight(randomFrom(pool));
            step++;

            if (step < delays.length) {
                flickerTimer = window.setTimeout(tick, delays[step]);

                return;
            }

            settle(winner);
        }

        flickerTimer = window.setTimeout(tick, delays[0]);
    }

    /**
     * Holds the chosen student, large, until the next tap.
     */
    function settle(index) {
        stopCycling();
        clearProgress();
        state = 'showing';

        tiles.forEach(function (tile, at) {
            tile.element.classList.remove('sp-picker-flicker');
            tile.element.classList.toggle('sp-picker-dim', at !== index);
            tile.element.classList.toggle('sp-picker-spotlight', at === index);
        });

        celebrate(tiles[index]);
        room.setStatus(room.text('pickerTapAgain'));
    }

    /* --------------------------------------------------------- the click */

    /**
     * Which third of the room was tapped: left, middle or right.
     *
     * Measured against the room element's own box rather than the window,
     * so the bands line up with what the teacher can see.
     */
    function thirdOf(event) {
        var rect = roomEl.getBoundingClientRect();

        if (!rect.width) {
            return 'mid';
        }

        var position = (event.clientX - rect.left) / rect.width;

        if (position < 1 / 3) {
            return 'low';
        }

        return position < 2 / 3 ? 'mid' : 'high';
    }

    /**
     * One tap does one of two things, depending on what is happening: while
     * the highlight is running it chooses, and while a choice is held it
     * starts running again. A tap during the slowing-down is ignored, so an
     * impatient second tap cannot cut the moment short.
     */
    function onRoomClick(event) {
        if (state === 'cycling') {
            chooseFromThird(thirdOf(event));

            return;
        }

        if (state === 'showing') {
            startCycling();
        }
    }

    /* --------------------------------------------------------------- load */

    function load(shell, payload) {
        room = shell;
        config = payload;
        roomEl = document.getElementById('spRoom');
        STEP = shell.STEP();

        // config.canEdit is false for this mode (room.php sets it so), so
        // room.js's own start() shows its usual read-only status text -
        // not accurate here, this is the teacher's own live tool, not a
        // colleague's shared layout.
        room.setStatus('');

        buildBackdrop();
        buildTiles();
        buildPools();

        roomEl.addEventListener('click', onRoomClick);

        // The one time this starts itself with no tap: entering the mode.
        // Every restart after that is in response to a click (see
        // onRoomClick).
        startCycling();
    }

    /* ----------------------------------------------------------- the mode */

    window.SeatingPlanRoom.register({
        id: 'picker',

        load: load,

        // This mode never lets room.js's own item pipeline run (canEdit is
        // false, and getItems returns nothing for it to draw) - it manages
        // every element on screen itself, inside its own layer.
        getItems: function () {
            return [];
        },
        specOf: function () {
            return {};
        },
        buildArt: function () {
            return document.createElement('div');
        }
    });
}());
