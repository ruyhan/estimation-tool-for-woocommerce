/* global jQuery, estitofo_admin */
jQuery(function ($) {
    'use strict';

    var ajaxUrl = estitofo_admin.ajax_url;
    var adminPostUrl = estitofo_admin.admin_post_url;
    var nonceProducts = estitofo_admin.nonce_products;
    var nonceDownload = estitofo_admin.nonce_download;
    var nonceAdmin = estitofo_admin.nonce_admin;
    var i18n = estitofo_admin.i18n || {};

    // The dialog uses the shared .qly-modal shell, whose overlay is a flex
    // container toggled by the [hidden] attribute — jQuery .show() would force
    // display:block and break the centering, so drive `hidden` directly.
    var MODAL_TITLE_DEFAULT = null;

    function openModal(title) {
        var $overlay = $('#estimation-products-modal');
        var $title = $('#estimation-modal-title');
        if (MODAL_TITLE_DEFAULT === null) {
            MODAL_TITLE_DEFAULT = $title.text();
        }
        $title.text(title || MODAL_TITLE_DEFAULT);
        // The PDF button only makes sense for the products view.
        $overlay.find('.download-pdf-btn').toggle(!title);
        // Drop any action button a previous dialog added to the footer.
        $overlay.find('.qly-modal-injected').remove();
        $overlay.prop('hidden', false);
        $('body').addClass('qly-modal-open');
    }

    function closeModal() {
        $('#estimation-products-modal').prop('hidden', true);
        $('body').removeClass('qly-modal-open');
    }

    // Backdrop click and Escape, matching the Pro quote editor.
    $(document).on('click', '#estimation-products-modal', function (e) {
        if (e.target === this) {
            closeModal();
        }
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && !$('#estimation-products-modal').prop('hidden')) {
            closeModal();
        }
    });

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
        openModal();
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
        openModal(i18n.edit_notes || 'Edit notes');

        $.post(ajaxUrl, {
            action: 'estitofo_get_products',
            id: id,
            include_notes: 1,
            _ajax_nonce: nonceProducts
        }, function (response) {
            if (response && response.success) {
                var $box = $('<div class="wc-est-notes-editor"></div>');
                var $textarea = $('<textarea rows="8"></textarea>')
                    .attr('placeholder', i18n.admin_notes_placeholder || '')
                    .val(response.data.admin_notes || '');
                $box.append($textarea);
                $('#products-list-container').empty().append($box);

                // Save lives in the footer next to Close, so both dialog
                // actions sit in the same place.
                var $save = $('<button type="button" class="qly-btn qly-btn--primary qly-modal-injected"></button>')
                    .text(i18n.save || 'Save');
                $('#estimation-products-modal').find('.qly-modal__foot').append($save);

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
