/* global jQuery, estitofo_admin */
jQuery(function ($) {
    'use strict';

    var ajaxUrl = estitofoAdmin.ajax_url;
    var adminPostUrl = estitofoAdmin.admin_post_url;
    var nonceProducts = estitofoAdmin.nonce_products;
    var nonceDownload = estitofoAdmin.nonce_download;
    var nonceAdmin = estitofoAdmin.nonce_admin;
    var i18n = estitofoAdmin.i18n || {};

    function closeModal() {
        $('#estimation-products-modal').hide();
    }

    function showFlash($row, text) {
        var $flash = $('<span class="wc-est-flash" style="margin-left:6px;color:#16a34a;font-size:12px;"></span>').text(text);
        $row.find('.wc-est-flash').remove();
        $row.find('.wc-est-status').after($flash);
        setTimeout(function () { $flash.fadeOut(500, function () { $(this).remove(); }); }, 1500);
    }

    $(document).on('click', '.view-products-btn', function (e) {
        e.preventDefault();
        var estimationId = parseInt($(this).data('id'), 10);
        if (!estimationId) {
            return;
        }
        $('#products-list-container').empty().append(
            $('<div class="loading-message"></div>').text(i18n.loading || 'Loading...')
        );
        $('#estimation-products-modal').show();
        $.post(ajaxUrl, {
            action: 'estitofo_get_products',
            id: estimationId,
            _ajax_nonce: nonceProducts
        }, function (response) {
            if (response && response.success) {
                $('#products-list-container').html(response.data.html);
                $('.download-pdf-btn').data('id', estimationId);
            } else {
                var msg = (response && response.data) ? response.data : 'Error';
                $('#products-list-container').empty().append(
                    $('<div class="notice notice-error"></div>').text(msg)
                );
            }
        }, 'json');
    });

    $(document).on('click', '.close-modal', function (e) {
        e.preventDefault();
        closeModal();
    });

    $(document).on('click', '.download-pdf-btn', function (e) {
        e.preventDefault();
        var estimationId = parseInt($(this).data('id'), 10);
        if (!estimationId) {
            return;
        }
        var $form = $('<form/>', {
            method: 'POST',
            action: adminPostUrl,
            target: '_blank'
        });
        $form.append($('<input/>', { type: 'hidden', name: 'action', value: 'estitofo_admin_pdf' }));
        $form.append($('<input/>', { type: 'hidden', name: 'id', value: estimationId }));
        $form.append($('<input/>', { type: 'hidden', name: '_wpnonce', value: nonceDownload }));
        $('body').append($form);
        $form.trigger('submit');
        $form.remove();
    });

    // Inline workflow status update.
    $(document).on('change', '.wc-est-status', function () {
        var $sel = $(this);
        var id = parseInt($sel.data('id'), 10);
        var value = $sel.val();
        var $row = $sel.closest('tr');
        $sel.prop('disabled', true);
        $.post(ajaxUrl, {
            action: 'estitofo_update_workflow',
            id: id,
            workflow_status: value,
            _ajax_nonce: nonceAdmin
        }, function (resp) {
            $sel.prop('disabled', false);
            if (resp && resp.success) {
                showFlash($row, i18n.saved || 'Saved');
            }
        }, 'json');
    });

    // Notes editor (per-row inline modal).
    $(document).on('click', '.edit-notes-btn', function (e) {
        e.preventDefault();
        var id = parseInt($(this).data('id'), 10);
        if (!id) return;

        $('#products-list-container').empty().append(
            $('<div class="loading-message"></div>').text(i18n.loading || 'Loading...')
        );
        $('#estimation-products-modal').show();

        $.post(ajaxUrl, {
            action: 'estitofo_get_products',
            id: id,
            include_notes: 1,
            _ajax_nonce: nonceProducts
        }, function (response) {
            if (response && response.success) {
                var $box = $('<div class="wc-est-notes-editor"></div>');
                $box.append($('<h3></h3>').text(i18n.edit_notes || 'Edit notes'));
                var $textarea = $('<textarea rows="8" style="width:100%;"></textarea>')
                    .attr('placeholder', i18n.admin_notes_placeholder || '')
                    .val(response.data.admin_notes || '');
                $box.append($textarea);
                var $save = $('<button type="button" class="button button-primary"></button>').text(i18n.save || 'Save');
                $box.append($save);
                $('#products-list-container').empty().append($box);

                $save.on('click', function () {
                    $.post(ajaxUrl, {
                        action: 'estitofo_save_admin_notes',
                        id: id,
                        admin_notes: $textarea.val(),
                        _ajax_nonce: nonceAdmin
                    }, function (r) {
                        if (r && r.success) {
                            $save.text(i18n.saved || 'Saved');
                            setTimeout(closeModal, 800);
                        }
                    }, 'json');
                });
            }
        }, 'json');
    });
});
