import assert from 'node:assert/strict';
import { initInteractiveUi, registerInitializer, registeredInitializers } from '../assets/js/core/registry.js';

let calls = 0;
const root = { marker: 'characterization-root' };
registerInitializer('characterization', (receivedRoot) => {
  assert.equal(receivedRoot, root);
  calls += 1;
});
registerInitializer('characterization', (receivedRoot) => {
  assert.equal(receivedRoot, root);
  calls += 1;
});

assert.equal(registeredInitializers().filter((name) => name === 'characterization').length, 1);
initInteractiveUi(root);
initInteractiveUi(root);
assert.equal(calls, 2, 'Initializer replacement/re-entry behavior changed.');

console.log('[frontend-registry-characterization] PASS');
