/* global jQuery, wp */
jQuery(function ($) {
    'use strict';

    // Color picker on .wc-est-color fields (kept for legacy class).
    if ($.fn.wpColorPicker) {
        $('.wc-est-color').wpColorPicker();
    }

    // Logo / image picker via wp.media.
    $('.wc-est-upload-logo').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $cell = $btn.closest('.wc-est-media-field');
        if (typeof wp === 'undefined' || !wp.media) {
            return;
        }
        var frame = wp.media({
            title: $btn.data('title') || 'Select Image',
            multiple: false,
            library: { type: 'image' }
        });
        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            $cell.find('input[type=url]').val(att.url);
            $cell.find('input#estitofo_logo_id').val(att.id || '');
            var $preview = $cell.find('.wc-est-logo-preview');
            var $img = $preview.find('img');
            if ($img.length) { $img.attr('src', att.url); }
            $preview.show();
            $cell.find('.wc-est-remove-logo').show();
        });
        frame.open();
    });

    // Remove logo button.
    $(document).on('click', '.wc-est-remove-logo', function (e) {
        e.preventDefault();
        var $cell = $(this).closest('.wc-est-media-field');
        $cell.find('input[type=url]').val('');
        $cell.find('input#estitofo_logo_id').val('');
        $cell.find('.wc-est-logo-preview').hide();
        $(this).hide();
    });

    // Tools-tab "copy snippet" buttons.
    $(document).on('click', '.wc-est-copy', function () {
        var $btn = $(this);
        var text = $btn.data('copy') || $btn.prev('code').text();
        var done = function () {
            var prev = $btn.text();
            $btn.text(estitofoAdmin.i18n.copied || 'Copied!').addClass('is-copied');
            setTimeout(function () { $btn.text(prev).removeClass('is-copied'); }, 1400);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, done);
        } else {
            var $ta = $('<textarea>').val(text).appendTo('body').select();
            try { document.execCommand('copy'); } catch (e) {}
            $ta.remove();
            done();
        }
    });
});
