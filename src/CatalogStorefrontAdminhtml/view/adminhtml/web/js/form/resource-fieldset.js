define(['Magento_Ui/js/form/components/fieldset', 'uiRegistry', 'jquery', 'mage/translate'], function (Fieldset, registry, $, $t) {
    'use strict';
    return Fieldset.extend({
        initialize: function () {
            this._super();
            this.fields = {};
            this.metadata = {attributes: []};
            this.metadataSequence = 0;
            registry.get(this.fieldNames.map(function (name) { return this.name + '.' + name; }, this), function () {
                Array.prototype.forEach.call(arguments, function (field, index) { this.fields[this.fieldNames[index]] = field; }, this);
                ['type', 'protection', 'book_mode', 'book_id', 'attribute', 'value_source', 'operator'].forEach(function (name) {
                    if (this.fields[name]) this.fields[name].value.subscribe(this.updateRules.bind(this));
                }, this);
                if (this.resourceKind === 'policies') {
                    this.fields.source_id.value.subscribe(function () { this.loadMetadata(true); }.bind(this));
                    this.loadMetadata(false);
                }
                this.updateRules();
            }.bind(this));
            return this;
        },
        updateRules: function () {
            if (this.updating) return;
            this.updating = true;
            try {
                var f = this.fields, nativeView, privateView, mode, attribute, hasOptions, trigger;
                if (this.resourceKind === 'views') {
                    nativeView = f.type.value() === 'platform_store_view';
                    f.type.disabled(!this.editable || this.resourceId > 0);
                    ['enabled', 'name', 'code', 'source_id', 'stock_id', 'protection', 'book_mode', 'book_ids', 'book_id', 'layer_ids', 'policy_ids'].forEach(function (name) { f[name].visible(!nativeView); });
                    f.native_store_id.visible(nativeView);
                    if (!nativeView) {
                        privateView = f.protection.value() === 'private';
                        if (privateView) f.book_mode.value('single');
                        f.book_mode.disabled(!this.editable || privateView);
                        mode = f.book_mode.value();
                        f.book_ids.visible(mode === 'selected');
                        f.book_id.visible(mode === 'single');
                    }
                }
                if (this.resourceKind === 'books') f.parent_id.visible(f.type.value() !== 'platform_root');
                if (this.resourceKind === 'policies') {
                    attribute = this.metadata.attributes.find(function (item) { return item.value === f.attribute.value(); });
                    hasOptions = Boolean(attribute && attribute.options.length);
                    trigger = f.value_source.value() === 'trigger';
                    var generic = f.type.value() === 'generic';
                    f.option_values.visible(generic && hasOptions && !trigger);
                    f.values.visible(generic && !hasOptions && !trigger);
                    f.trigger.visible(generic && trigger);
                    if (attribute && this.optionAttribute !== attribute.value) {
                        this.optionAttribute = attribute.value;
                        var selected = f.option_values.value();
                        f.option_values.setOptions(attribute.options);
                        f.option_values.value((Array.isArray(selected) ? selected : []).filter(function (value) { return attribute.options.some(function (option) { return option.value === String(value); }); }).map(String));
                    }
                }
            } finally { this.updating = false; }
        },
        loadMetadata: function (clear) {
            var f = this.fields, source = f.source_id.value(), selected = clear ? '' : f.attribute.value(), sequence = ++this.metadataSequence;
            if (this.metadataRequest) this.metadataRequest.abort();
            this.metadata = {attributes: []};
            this.optionAttribute = null;
            if (clear) { f.attribute.value(''); f.option_values.value([]); f.values.value(''); }
            f.attribute.disabled(true);
            if (!source) { f.attribute.setOptions([]); this.updateRules(); return; }
            this.metadataRequest = $.getJSON(this.metadataUrl, {source_id: source}).done(function (data) {
                if (sequence !== this.metadataSequence) return;
                this.metadata = data;
                f.attribute.setOptions(data.attributes.map(function (item) { return {value: item.value, label: item.label}; }));
                f.attribute.value(selected);
                f.attribute.disabled(!this.editable || !data.available);
                f.attribute.error(data.available ? false : $t('This Catalog Source has no imported filter metadata yet.'));
                this.updateRules();
            }.bind(this)).fail(function (xhr, status) {
                if (status === 'abort' || sequence !== this.metadataSequence) return;
                f.attribute.setOptions([]);
                f.attribute.error($t('Imported source metadata is unavailable.'));
                this.updateRules();
            }.bind(this));
        },
        destroy: function () {
            if (this.metadataRequest) this.metadataRequest.abort();
            return this._super();
        }
    });
});
