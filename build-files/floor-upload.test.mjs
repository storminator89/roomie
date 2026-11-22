import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const source = await readFile(new URL('../assets/roomplan/floor-upload.js', import.meta.url));
const { floorUploadFileError, suggestedFloorTitle } = await import(
    'data:text/javascript;base64,' + source.toString('base64')
);

test('floorplan drop and picker share the same file type and size constraints', () => {
    assert.equal(floorUploadFileError({size: 5 * 1024 * 1024, type: 'image/png', name: 'EG.png'}), '');
    assert.equal(floorUploadFileError({size: 123, type: '', name: 'OG.JPEG'}), '');
    assert.equal(floorUploadFileError({size: 123, type: 'application/octet-stream', name: 'OG.jpg'}), '');
    assert.match(floorUploadFileError({size: 0, type: 'image/png', name: 'EG.png'}), /Bild/);
    assert.match(floorUploadFileError({size: 1 + 5 * 1024 * 1024, type: 'image/png', name: 'EG.png'}), /5 MB/);
    assert.match(floorUploadFileError({size: 123, type: 'image/svg+xml', name: 'OG.png'}), /PNG/);
    assert.match(floorUploadFileError({size: 123, type: '', name: 'OG.svg'}), /PNG/);
});

test('name proposal removes extension, normalizes separators and preserves UTF-16 pairs', () => {
    assert.equal(suggestedFloorTitle('   1.-Stock_Nord-OST.PNG'), '1. Stock Nord OST');
    assert.equal(suggestedFloorTitle('.jpg'), '');
    assert.equal(suggestedFloorTitle('x'.repeat(119) + '🏢' + '.JpEg'), 'x'.repeat(119));
});
