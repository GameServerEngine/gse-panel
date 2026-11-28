// Pricing is informational. An offer becomes selectable only after the current
// configuration receives a fresh answer from the Panel.
function initConfiguration(form, initial) {
    const slots = form.elements.slots;
    const months = form.elements.qty;
    const radios = Array.from(form.querySelectorAll('[data-city]'));
    const checkout = form.elements.add_to_cart;
    const tier = form.elements.tier_key;
    let quote = initial;
    let generation = 0;
    let timer;

    function render() {
        let offer = null;
        for (const radio of radios) {
            const id = quote.locations[radio.dataset.city] || 0;
            radio.disabled = !id;
            radio.value = String(id);
            if (!id) radio.checked = false;
            radio.parentElement.classList.toggle('location-unavailable', !id);
            radio.parentElement.querySelector('span').hidden = !!id;
            if (radio.checked) offer = quote.prices[id];
        }
        const monthly = offer ? offer.monthly : quote.preview;
        const extras = Array.from(form.querySelectorAll('.order-option-input:checked'))
            .reduce((total, input) => total + Number(input.dataset.price), 0);
        form.querySelector('#totalPrice').textContent = monthly == null ? 'No price for this configuration.'
            : 'Estimated total: $' + (monthly * Number(months.value) + extras).toFixed(2);
        form.querySelector('#invoiceDuration').textContent = months.value + (Number(months.value) === 1 ? ' month' : ' months');
        tier.value = offer ? offer.tier : '';
        if (checkout) checkout.disabled = !offer;
        const domains = form.elements.sd_domain;
        if (domains) {
            for (const option of domains.options) {
                const node = Number(option.dataset.node);
                option.hidden = option.disabled = !!node && node !== (offer && offer.host);
            }
            if (domains.selectedOptions[0]?.disabled) {
                domains.selectedIndex = Array.from(domains.options).findIndex(option => !option.disabled);
                domains.dispatchEvent(new Event('change'));
            }
        }
    }

    function refresh() {
        const current = ++generation;
        clearTimeout(timer);
        radios.forEach(r => { r.disabled = true; });
        if (checkout) checkout.disabled = true;
        timer = setTimeout(async () => {
            try {
                const params = new URLSearchParams({game: form.elements.home_cfg_id.value, slots: slots.value});
                const res = await fetch('api/configuration_quote.php?' + params, {cache: 'no-store'});
                if (!res.ok) throw Error();
                const next = await res.json();
                if (current !== generation) return;
                quote = next;
            } catch {
                if (current !== generation) return;
                quote = {locations: {}, prices: {}, preview: null};
            }
            render();
        }, 100);
    }

    radios.forEach(r => r.addEventListener('change', render));
    slots.addEventListener('input', refresh);
    months.addEventListener('input', refresh);
    form.querySelectorAll('.order-option-input').forEach(el => el.addEventListener('change', refresh));
    render();
}
