define(['jquery','mage/translate'], function($, $t) {
    'use strict';
    return function(config, element) {
        var $button = $(element);
        var ajaxUrl = config.ajaxUrl;

        $button.on('click', function(e){
            e.preventDefault();
            var ids=[];
            $('input.admin__control-checkbox:checked').each(function(){ if(this.value) ids.push(this.value); });
            if(!ids.length){ alert($t('Please select at least one product.')); return; }
            $.ajax({ url: ajaxUrl, type:'POST', dataType:'json', data:{ ids: ids, form_key: window.FORM_KEY }, showLoader:true })
                .done(function(resp){ if(resp && resp.success){ alert(resp.message || ids.length+' processed'); location.reload(); } else { alert(resp.message || $t('Error')); } })
                .fail(function(xhr){ console.error(xhr); alert($t('Request failed.')); });
        });
    };
});
