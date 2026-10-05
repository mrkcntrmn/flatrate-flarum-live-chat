/**
 * Canonical Brand board keys from the embedded room catalog.
 * Room identity is boardKey, never the public tag slug.
 */
export const BRAND_BOARD_KEYS = [
    'acura',
    'alfa-romeo',
    'aston-martin',
    'audi',
    'bentley',
    'bmw',
    'cdjr',
    'buick',
    'cadillac',
    'chevrolet',
    'chrysler',
    'dodge',
    'ferrari',
    'ford',
    'genesis',
    'gm',
    'gmc',
    'honda',
    'hyundai',
    'infiniti',
    'jlr',
    'jaguar',
    'land-rover',
    'jeep',
    'kia',
    'lamborghini',
    'lexus',
    'lincoln',
    'maserati',
    'mazda',
    'mclaren',
    'mercedes-benz',
    'mini',
    'mitsubishi',
    'nissan',
    'other-makes',
    'porsche',
    'ram',
    'range-rover',
    'rivian',
    'subaru',
    'tesla',
    'toyota',
    'volkswagen',
    'volvo',
];

const BRAND_BOARD_KEY_SET = new Set(BRAND_BOARD_KEYS);

export function isCanonicalBrandBoardKey(boardKey) {
    return typeof boardKey === 'string' && BRAND_BOARD_KEY_SET.has(boardKey);
}

export function brandRoomKey(boardKey) {
    if (!isCanonicalBrandBoardKey(boardKey)) return null;
    return `${boardKey}-live`;
}

export function brandRoomHref(boardKey) {
    const roomKey = brandRoomKey(boardKey);
    return roomKey ? `/messages/live/${roomKey}` : null;
}
