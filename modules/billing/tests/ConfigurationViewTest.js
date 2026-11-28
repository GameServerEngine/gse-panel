const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const element = value => ({
    value, dataset: {}, events: {}, addEventListener(name, fn) { this.events[name] = fn; }
});
const radio = city => Object.assign(element('0'), {
    dataset: {city}, checked: false, parentElement: {
        classList: {toggle(name, disabled) { this.grey = disabled; }},
        querySelector() { return {}; }
    }
});
async function test() {
    const radios = [radio(6), radio(4)];
    const fields = {slots: element(16), qty: element(1), tier_key: element(''), home_cfg_id: element(10), add_to_cart: element('')};
    const price = element('');
    const form = {elements: fields, querySelectorAll: s => s === '[data-city]' ? radios : [],
        querySelector: s => s === '#totalPrice' ? price : element('')};
    let next, tick;
    const vmContext = {URLSearchParams, clearTimeout() {}, setTimeout(cb) { tick = cb; },
        fetch: async () => ({ok: true, json: async () => next})};
    vm.runInNewContext(fs.readFileSync(path.join(root,'configuration.js'),'utf8'), vmContext);
    radios[1].checked = true;
    vmContext.initConfiguration(form, {locations: {6:0,4:109}, prices: {109:{monthly:32,tier:'',host:9}}, preview:32});
    assert(radios[0].disabled && radios[0].parentElement.classList.grey);
    assert(!radios[1].disabled && !fields.add_to_cart.disabled);
    next = {locations:{6:0,4:0},prices:{},preview:64};
    fields.slots.value = 32;
    fields.slots.events.input();
    assert(fields.add_to_cart.disabled);
    await tick();
    assert(radios.every(r => r.disabled && !r.checked));
    fields.qty.value = 3;
    fields.qty.events.input();
    await tick();
    assert.match(price.textContent,/192\.00/);
    assert(fields.add_to_cart.disabled);
    assert.match(fs.readFileSync(path.join(root,'order.php'),'utf8'), /&mdash; Unavailable/);
    const card = fs.readFileSync(path.join(root,'serverlist.php'),'utf8');
    assert.match(card,/availableCfg === null.*Unavailable/);
    assert.match(card,/<a href=.*Configure Server<\/a>/);
    console.log('Configuration view passed');
}
test().catch(e => { console.error(e); process.exitCode=1; });
