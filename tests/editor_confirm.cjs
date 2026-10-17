// Exercise the editor submit/replay path without a browser or network.
const vm = require('node:vm'), fs = require('node:fs'), assert = require('node:assert/strict');
(async () => {
  for (const answer of [false, true]) {
    const handlers = {}, calls = []; let sent = 0;
    const form = {dataset: {}, elements: {body: {value: '<p>Текст</p>'}}, addEventListener: (name, fn) => handlers[name] = fn,
      requestSubmit: button => {const event = {submitter: button, prevented: false, preventDefault() {this.prevented = true;}}; handlers.submit(event); if (!event.prevented) sent++;}};
    const context = {document: {querySelector: selector => selector === '#editor' ? form : {}},
      window: {addEventListener() {}, ordaConfirm: async (...args) => {calls.push(args); return answer;}},
      location: {hash: ''}, setInterval() {}, setTimeout: fn => fn(), FormData: class {}, console};
    vm.runInNewContext(fs.readFileSync('public/assets/js/editor.js', 'utf8'), context);
    const button = {dataset: {editorConfirm: 'Удалить вариант 19?'}};
    const event = {submitter: button, preventDefault() {this.prevented = true;}};
    await handlers.submit(event); await new Promise(resolve => setImmediate(resolve));
    assert.equal(calls.length, 1); assert.equal(event.prevented, true); assert.equal(sent, answer ? 1 : 0);
    assert.equal(button.dataset.confirm, undefined, 'global confirmation must not handle editor buttons');
  }
  console.log('OK: one confirmation; cancel sends nothing; accept submits once through editor replay');
})().catch(error => {console.error(error); process.exit(1);});
