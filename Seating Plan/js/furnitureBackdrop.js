/**
 * Shared read-only furniture backdrop used by the interactive room modes.
 * Mode-specific callbacks may observe normalized furniture (for example to
 * collect chair positions) without taking over rendering responsibilities.
 */
(function (window) {
    'use strict';

    window.SeatingPlanFurnitureBackdrop = {
        build: function (config, step, roomElement, onFurniture) {
            var backdrop = document.createElement('div');
            backdrop.className = 'sp-backdrop';

            (config.items || []).forEach(function (furniture) {
                var item = {
                    type: furniture.type,
                    posX: parseInt(furniture.posX, 10),
                    posY: parseInt(furniture.posY, 10),
                    rotation: parseInt(furniture.rotation, 10) || 0,
                    flipped: furniture.flipped === 'Y',
                    sizeX: parseInt(furniture.sizeX, 10) || step,
                    sizeY: parseInt(furniture.sizeY, 10) || step
                };
                var definition = config.catalogue[item.type] || {};
                var turned = item.rotation === 1 || item.rotation === 3;
                var box = document.createElement('div');
                box.className = 'sp-item sp-item-backdrop';
                box.dataset.layer = definition.layer || 'base';
                box.style.setProperty('--c', item.posX);
                box.style.setProperty('--r', item.posY);
                box.style.setProperty('--w', turned ? item.sizeY : item.sizeX);
                box.style.setProperty('--h', turned ? item.sizeX : item.sizeY);
                box.appendChild(window.SeatingPlanFurnitureArt.buildArt(item, definition));
                backdrop.appendChild(box);

                if (onFurniture) {
                    onFurniture(item, definition);
                }
            });

            roomElement.appendChild(backdrop);
            return backdrop;
        }
    };
}(window));