define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/confirm',
    'mage/translate'
], function ($, alert, confirm, $t) {
    'use strict';

    return function (url, formKey) {
        function getSelectedIds() {

            alert('sdfsdfsd');
            var ids = [];

            // common checkbox selectors in UI grids
            $('input[data-role="select-row"]:checked, input[name="selected[]"]:checked, input[data-role="grid-row-checkbox"]:checked, input[type="checkbox"].data-grid-checkbox:checked').each(function () {
                var v = $(this).val();
                if (v) ids.push(v);
            });

            // fallback: massaction input (comma separated)
            if (!ids.length) {
                var mass = $('input[name="selected"]').val() || $('input[name="massaction[]"]').val();
                if (mass) {
                    ids = mass.toString().split(',').filter(function (i) { return i; });
                }
            }

            // unique
            ids = $.grep(ids, function(v, i){ return $.inArray(v, ids) === i; });
            return ids;
        }

        var selected = getSelectedIds();

        if (!selected || selected.length === 0) {
            alert({
                title: $t('Attention'),
                content: $t('Please select product(s).'),
                actions: { always: function() {} }
            });
            return;
        }

        confirm({
            title: $t('Send Products'),
            content: $t('Are you sure you want to send the selected product(s)?'),
            actions: {
                confirm: function () {
                    $.ajax({
                        url: url,
                        type: 'POST',
                        dataType: 'json',
                        data: { form_key: formKey, product_ids: selected },
                        success: function (res) {
                            if (res && res.success) {
                                alert({
                                    title: $t('Success'),
                                    content: res.message || $t('Products sent successfully.'),
                                    actions: { always: function () { window.location.reload(); } }
                                });
                            } else {
                                alert({
                                    title: $t('Error'),
                                    content: (res && res.message) ? res.message : $t('An error occurred.'),
                                    actions: { always: function () {} }
                                });
                            }
                        },
                        error: function () {
                            alert({
                                title: $t('Error'),
                                content: $t('An error occurred while sending products.'),
                                actions: { always: function () {} }
                            });
                        }
                    });
                },
                cancel: function () { /* canceled */ }
            }
        });
    };
});
