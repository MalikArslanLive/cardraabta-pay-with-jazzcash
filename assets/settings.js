(function() {
    const prefix = 'woocommerce_crj_card_';
    const env = document.getElementById(prefix + 'environment');
    if (!env) return;
    const names = ['merchant_id', 'merchant_password', 'integrity_salt'];
    const visible = names.map(n => document.getElementById(prefix + n));
    const hidden = names.map(n => document.getElementById(prefix + 'live_' + n));
    if (visible.some(x => !x) || hidden.some(x => !x)) return;
    const form = env.closest('form');
    const saved = {
        sandbox: visible.map(x => x.value),
        live: hidden.map(x => x.value)
    };
    let current = 'sandbox';

    function show(which) {
        if (which !== current) {
            saved[current] = visible.map(x => x.value);
            visible.forEach((x, i) => {
                x.value = saved[which][i] || '';
            });
            current = which;
        }
        names.forEach((name, i) => {
            const row = hidden[i].closest('tr');
            if (row) row.style.display = 'none';
            const label = visible[i].closest('tr')?.querySelector('th label');
            if (label) label.textContent = name === 'merchant_id' ? 'Merchant ID' : name === 'merchant_password' ? 'Merchant Password' : 'Integrity Salt';
        });
    }
    show(env.value === 'live' ? 'live' : 'sandbox');
    env.addEventListener('change', () => show(env.value === 'live' ? 'live' : 'sandbox'));
    if (form) form.addEventListener('submit', () => {
        saved[current] = visible.map(x => x.value);
        // Actual WooCommerce field names remain unchanged for backward compatibility.
        visible.forEach((x, i) => {
            x.value = saved.sandbox[i] || '';
            hidden[i].value = saved.live[i] || '';
        });
    });
})();
