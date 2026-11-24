const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/payment.js'), 'utf8');
const handler = source.slice(source.indexOf('  function handleStatusData(data) {'), source.indexOf('  function checkTransactionStatus(opts) {'));
let checks = 0;
function run(data, resolved = false) {
  const calls = [];
  const context = {statusResolved: resolved, currentTx: null, cfg: {i18n: {reviewMessage: 'Review', cancelled: 'Cancelled', expired: 'Expired'}},
    showSuccess: data => calls.push(['success', data]), showFailed: (...args) => calls.push(['failed', ...args])};
  vm.runInNewContext(handler, context);
  context.handleStatusData(data);
  checks++;
  return calls;
}
assert.deepEqual(run({status:'confirmed', normalized_status:'manual_review', is_paid:false}), [['failed','Review',true]]);
assert.deepEqual(run({status:'confirmed', is_paid:false}), []);
assert.deepEqual(run({status:'confirmed'}), []);
assert.equal(run({status:'confirmed', is_paid:true})[0][0], 'success');
assert.deepEqual(run({status:'confirmed', normalized_status:'mismatch', is_paid:false}), []);
assert.deepEqual(run({status:'confirmed', is_paid:true}, true), []);
assert.deepEqual(run({status:'pending'}), []);
assert.deepEqual(run({status:'expired'}), [['failed','Expired']]);
console.log(`Payment UI regression: ${checks} checks passed.`);
