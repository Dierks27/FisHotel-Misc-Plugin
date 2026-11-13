/**
 * The pure helpers of the Gift Cards scripts: the checkout's amount box
 * (cleaned to dollars and cents, never more than the order) and the admin
 * page's "are you sure?" questions.
 *
 * Run from the plugin root:  node includes/sections/gift-cards/tests/gift-cards.test.js
 */
'use strict';

const path = require('path');
const checkout = require(path.join(__dirname, '..', 'js', 'gift-cards-checkout.js'));
const admin = require(path.join(__dirname, '..', 'js', 'gift-cards-admin.js'));

let checks = 0;
let failures = 0;
function check(label, ok) {
	checks++;
	if (ok) {
		console.log('  ok   ' + label);
	} else {
		failures++;
		console.log('  FAIL ' + label);
	}
}

console.log('The amount box');
check('"12.5" is 12.50', checkout.cleanAmount('12.5') === '12.50');
check('"$1,000" is 1000.00', checkout.cleanAmount('$1,000') === '1000.00');
check('letters are not an amount', checkout.cleanAmount('ten') === '');
check('three decimals are not an amount', checkout.cleanAmount('1.005') === '');
check('a minus sign is not an amount', checkout.cleanAmount('-5') === '');
check('never more than the order: 80 on a 30.00 order is 30.00', checkout.clampAmount('80', '30.00') === '30.00');
check('less stays as typed', checkout.clampAmount('12', '30.00') === '12.00');
check('nothing typed stays empty (all of it)', checkout.clampAmount('', '30.00') === '');

console.log('\nAre you sure?');
check('an import asks', admin.question({ confirm: 'Copy now?' }, '', '') === 'Copy now?');
check('switching to Live asks', admin.question({ confirmLive: 'Live?' }, 'live', 'shadow') === 'Live?');
check('saving Live again does not', admin.question({ confirmLive: 'Live?' }, 'live', 'live') === '');
check('switching to Shadow does not', admin.question({ confirmLive: 'Live?' }, 'shadow', 'live') === '');

console.log('\n' + (failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed'));
process.exit(failures ? 1 : 0);
