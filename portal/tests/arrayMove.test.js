const assert = require('assert');
const { test } = require('node:test');

// Import arrayMove from the new shared utils file
const { arrayMove } = require('../js/utils.js');

test('arrayMove moves an element to a later position', () => {
    const arr = ['a', 'b', 'c', 'd', 'e'];
    const result = arrayMove(arr, 1, 3);
    assert.deepStrictEqual(result, ['a', 'c', 'd', 'b', 'e']);
});

test('arrayMove moves an element to an earlier position', () => {
    const arr = ['a', 'b', 'c', 'd', 'e'];
    const result = arrayMove(arr, 3, 1);
    assert.deepStrictEqual(result, ['a', 'd', 'b', 'c', 'e']);
});

test('arrayMove moves an element to the same position', () => {
    const arr = ['a', 'b', 'c'];
    const result = arrayMove(arr, 1, 1);
    assert.deepStrictEqual(result, ['a', 'b', 'c']);
});

test('arrayMove does not mutate the original array', () => {
    const arr = ['a', 'b', 'c'];
    const result = arrayMove(arr, 0, 2);
    assert.deepStrictEqual(arr, ['a', 'b', 'c']);
    assert.deepStrictEqual(result, ['b', 'c', 'a']);
});

test('arrayMove handles out of bounds `to` index by inserting at the end', () => {
    const arr = ['a', 'b', 'c'];
    const result = arrayMove(arr, 0, 10);
    assert.deepStrictEqual(result, ['b', 'c', 'a']);
});

test('arrayMove handling negative `to` index behaves as Array.prototype.splice', () => {
    const arr = ['a', 'b', 'c', 'd'];
    const result = arrayMove(arr, 0, -1);
    // splice removes 'a', leaves ['b', 'c', 'd']
    // inserts 'a' at -1 (before 'd')
    assert.deepStrictEqual(result, ['b', 'c', 'a', 'd']);
});

test('arrayMove handles out of bounds `from` index by not modifying array content', () => {
    const arr = ['a', 'b', 'c'];
    const result = arrayMove(arr, 10, 0);
    // splice removes nothing (returns undefined) and inserts undefined at 0
    assert.deepStrictEqual(result, [undefined, 'a', 'b', 'c']);
});

test('arrayMove with empty array inserts undefined', () => {
    const arr = [];
    const result = arrayMove(arr, 0, 0);
    assert.deepStrictEqual(result, [undefined]);
});
