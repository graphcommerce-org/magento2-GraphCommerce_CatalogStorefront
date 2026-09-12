define(['Magento_Ui/js/form/element/select', 'mage/translate'], function (Select, $t) {
    'use strict';
    return Select.extend({
        defaults: {
            listens: {sourceBinding: 'updateOperations', producerBinding: 'updateOperations', namespaceBinding: 'updateOperations'}
        },
        initialize: function () {
            this._super();
            this.updateOperations();
            return this;
        },
        updateOperations: function () {
            if (typeof this.value !== 'function' || typeof this.options !== 'function') return;
            var selected = this.value(), canonical = Boolean(this.sourceBinding || this.producerBinding || this.namespaceBinding),
                options = [{value: 'override', label: $t('Override')}];
            if (!canonical) options.push({value: 'merge', label: $t('Merge')});
            this.setOptions(options);
            // Never silently convert an existing merge into an override.
            this.value(selected);
            this.error(canonical && selected === 'merge' ? $t('Canonical layer feeds support Override only. Select an override field or remove the feed binding.') : false);
        }
    });
});
