// Run: node --test tests/
const test = require('node:test');
const assert = require('node:assert');
const search = require('../assets/js/trufi-search.js');

const sind = (n) => ({ name: n, slug: n.toLowerCase().replace(/\s+/g, '-') });
const lines = [
  { shortName: 'M', title: 'Trufi M', mode: 'BUS', sindicato: sind('Sindicato mixto Norte'), patterns: [{ from: 'Plaza', to: 'Sacaba' }] },
  { shortName: 'M', title: 'MicroBus M', mode: 'BUS', sindicato: sind('Sindicato mixto Sur'), patterns: [] },
  { shortName: '103', title: 'Trufi 103', mode: 'BUS', sindicato: sind('Sindicato mixto de transporte Bolivia'), patterns: [] },
  { shortName: '16', title: 'MicroBus 16', mode: 'BUS', sindicato: null, patterns: [{ from: 'Quillacollo', to: 'Centro' }] },
];

test('"M" matches line names only, never sindicato names', () => {
  const r = search.filterLines(lines, { query: 'M' });
  assert.deepStrictEqual(r.map((l) => l.title), ['Trufi M', 'MicroBus M']);
});

test('does not require a vehicle word like "bus"', () => {
  assert.strictEqual(search.filterLines(lines, { query: '103' }).length, 1);
});

test('falls back to origin/destination when no line is named like the query', () => {
  const r = search.filterLines(lines, { query: 'quillacollo' });
  assert.deepStrictEqual(r.map((l) => l.title), ['MicroBus 16']);
});

test('accent/case-insensitive and sindicato filter ANDed', () => {
  const r = search.filterLines(lines, { query: 'm', sindicato: 'sindicato-mixto-sur' });
  assert.deepStrictEqual(r.map((l) => l.title), ['MicroBus M']);
});

test('vehicle words narrow by title and match the rest as line name', () => {
  assert.deepStrictEqual(search.filterLines(lines, { query: 'trufi 103' }).map((l) => l.title), ['Trufi 103']);
  assert.deepStrictEqual(search.filterLines(lines, { query: 'micro m' }).map((l) => l.title), ['MicroBus M']);
  assert.deepStrictEqual(search.filterLines(lines, { query: 'microbus 16' }).map((l) => l.title), ['MicroBus 16']);
  assert.strictEqual(search.filterLines(lines, { query: 'trufi' }).length, 2);
});
