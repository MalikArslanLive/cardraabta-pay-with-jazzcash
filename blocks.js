(function() {
    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wc.wcSettings || !window.wp || !window.wp.element) return;
    const el = window.wp.element.createElement;
    const data = window.wc.wcSettings.getSetting('crj_card_data', {});
    if (!data || !data.title) return;
    const label = window.wp.htmlEntities.decodeEntities(data.title);
    const content = el('div', null,
        el('strong', null, 'Pay With JazzCash: hassle-free checkout in seconds.'),
        el('div', null, '💳 Debit/Credit Card'), el('div', null, '📱 Mobile Wallet'),
        el('div', null, '🎟️ Voucher'), el('div', null, '🏦 RaastQR'),
        el('small', null, 'Available methods depend on JazzCash merchant configuration.')
    );
    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'crj_card',
        label: label,
        ariaLabel: label,
        content: content,
        edit: content,
        canMakePayment: () => true,
        supports: {
            features: data.supports || ['products']
        }
    });
})();
