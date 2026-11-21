(function() {
    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wc.wcSettings || !window.wp || !window.wp.element) return;
    const el = window.wp.element.createElement;
    const data = window.wc.wcSettings.getSetting('crj_card_data', {});
    if (!data || !data.title) return;
    const label = window.wp.htmlEntities.decodeEntities(data.title);
    const content = el('div', { className: 'crj-payment-choice' },
        data.logo && el('img', { className: 'crj-checkout-logo', src: data.logo, alt: 'JazzCash', width: 130, height: 38 }),
        data.heading && el('strong', { className: 'crj-checkout-heading' }, data.heading),
        Array.isArray(data.methods) && el('ul', { className: 'crj-checkout-methods' },
            ...data.methods.map(method => el('li', { key: method.label },
                el('span', { className: 'crj-checkout-method-icon', 'aria-hidden': true }, method.icon),
                method.label
            ))
        )
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
