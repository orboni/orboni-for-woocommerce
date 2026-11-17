/**
 * Orboni in the block checkout: a label with the icon, the description when
 * it is picked. Nothing to fill in here: the buyer chooses a coin on Orboni's
 * payment page, where "Place order" sends them.
 */
(function (registry, settings, element, entities) {
    var data = settings.getSetting('orboni_data', {});
    var title = entities.decodeEntities(data.title || 'Crypto');
    var description = entities.decodeEntities(data.description || '');
    var h = element.createElement;

    function Label() {
        return h(
            'span',
            { style: { display: 'inline-flex', alignItems: 'center', gap: '0.5em', width: '100%' } },
            h('span', null, title),
            data.icon ? h('img', { src: data.icon, alt: '', width: 24, height: 24, style: { marginLeft: 'auto' } }) : null,
        );
    }

    function Content() {
        return description ? h('p', null, description) : null;
    }

    registry.registerPaymentMethod({
        name: 'orboni',
        label: h(Label),
        content: h(Content),
        edit: h(Content),
        // Offered in a currency Orboni takes; in dollars, from its minimum (Orboni checks any other).
        canMakePayment: function (args) {
            var totals = (args && args.cartTotals) || {};
            var currency = totals.currency_code || '';
            if ((data.currencies || ['USD']).indexOf(currency) === -1) {
                return false;
            }
            if (currency !== 'USD') {
                return true;
            }
            var minor = parseInt(totals.currency_minor_unit === undefined ? 2 : totals.currency_minor_unit, 10);
            var cents = (parseInt(totals.total_price || '0', 10) / Math.pow(10, minor)) * 100;

            return cents <= 0 || cents >= (data.minimum || 100);
        },
        ariaLabel: title,
        supports: { features: data.supports || ['products'] },
    });
})(window.wc.wcBlocksRegistry, window.wc.wcSettings, window.wp.element, window.wp.htmlEntities);
