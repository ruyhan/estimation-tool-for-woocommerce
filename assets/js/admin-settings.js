/* global jQuery, wp */
jQuery(function ($) {
    'use strict';

    // Anything that has to be attached to elements directly (rather than
    // delegated from the document) lives here, so it can be re-run after the
    // tab panel is swapped out. Delegated handlers below survive on their own.
    function initPanel(scope) {
        var $scope = $(scope || document);
        if ($.fn.wpColorPicker) {
            // wpColorPicker() wraps the input; running it twice on the same
            // field would nest the wrapper, so skip ones already initialised.
            $scope.find('.wc-est-color').not('.wp-color-picker').wpColorPicker();
        }
    }
    initPanel(document);

    // The whole settings screen re-announces itself after a tab swap.
    $(document).on('qly:panel', function (e, panel) { initPanel(panel); });

    // Logo / image picker via wp.media. Delegated so a swapped-in panel works
    // without rebinding.
    $(document).on('click', '.wc-est-upload-logo', function (e) {
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


/* ---------------------------------------------------------------------------
 * Customer e-mail: Edit / Preview tabs.
 *
 * Preview renders server-side through the same token pipeline a real send
 * uses, so what you see is what the customer gets. The result is written into
 * a sandboxed iframe via srcdoc — the template is arbitrary user HTML and must
 * never execute against the admin page it is previewed on.
 *
 * The render is deferred until the Preview tab is opened, and re-run whenever
 * the markup has changed since the last render.
 * ------------------------------------------------------------------------ */
jQuery(function ($) {
    'use strict';

    var cfg      = window.estitofoAdmin || {};
    var i18n     = cfg.i18n || {};
    var lastBody = null;

    function frameMsg(text, color) {
        return '<div style="font:14px -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;'
             + 'color:' + color + ';padding:44px 20px;text-align:center;">' + text + '</div>';
    }

    function render() {
        var $frame = $('#estitofo-email-frame');
        var body   = $('#estitofo_email_body').val() || '';

        if (body === lastBody) { return; }   // nothing changed since last render
        lastBody = body;

        $frame.attr('srcdoc', frameMsg(i18n.previewing || 'Rendering…', '#8b91a1'));

        $.post(cfg.ajaxUrl, {
            action: 'estitofo_preview_email',
            nonce: cfg.nonce,
            body: body
        }, function (resp) {
            if (resp && resp.success && resp.data && resp.data.html) {
                $frame.attr('srcdoc', resp.data.html);
            } else {
                lastBody = null;
                $frame.attr('srcdoc', frameMsg(i18n.previewFail || 'Could not render the preview.', '#b91c1c'));
            }
        }, 'json').fail(function () {
            lastBody = null;
            $frame.attr('srcdoc', frameMsg(i18n.previewFail || 'Could not render the preview.', '#b91c1c'));
        });
    }

    $(document).on('click', '.qly-editor__tab', function (e) {
        e.preventDefault();
        var $tab  = $(this);
        var name  = $tab.data('ee-tab');
        var $box  = $tab.closest('.qly-editor');

        $box.find('.qly-editor__tab').removeClass('is-active').attr('aria-selected', 'false');
        $tab.addClass('is-active').attr('aria-selected', 'true');
        $box.find('[data-ee-pane]').each(function () {
            $(this).prop('hidden', $(this).data('ee-pane') !== name);
        });

        if ('preview' === name) { render(); }
    });

    /* ------------------------------------------------------------------
       Settings tabs without a page load.

       The tab links keep their real href, so this is an enhancement: with
       JS off, a middle-click, or if the request fails, the browser just
       follows the link and the server renders the same panel. The panel
       markup comes from the same PHP method either way.
       ------------------------------------------------------------------ */
    var $panel = $('#qly-tab-panel');
    var $nav   = $('.qly-settings .nav-tab-wrapper');
    var loading = false;
    // Submitting is a legitimate way to leave a dirty panel.
    $(document).on('submit', '#qly-tab-panel form', function () { baseline = null; });

    // Whether the panel has unsaved edits is decided by comparing the form
    // against a snapshot taken once it has settled — not by listening for
    // change events. wpColorPicker fires `change` while initialising, so an
    // event-based flag reported the very first tab click as unsaved work.
    var baseline = null;

    function snapshot() {
        var $f = $panel.find('form');
        return $f.length ? $f.serialize() : '';
    }
    function isDirty() {
        return baseline !== null && snapshot() !== baseline;
    }

    function showTab(tab, push) {
        if (loading || !$panel.length) { return; }
        loading = true;
        $panel.addClass('is-loading');

        $.post(estitofoAdmin.ajaxUrl, {
            action: 'estitofo_settings_panel',
            nonce:  estitofoAdmin.nonce,
            tab:    tab
        }).done(function (res) {
            if (!res || !res.success || !res.data) {
                window.location.href = tabHref(tab);   // let the server handle it
                return;
            }
            $panel.html(res.data.html).attr('data-current-tab', tab);
            $nav.find('.nav-tab').removeClass('nav-tab-active')
                .filter('[data-tab="' + tab + '"]').addClass('nav-tab-active');

            if (push && window.history && window.history.pushState) {
                window.history.pushState({ qlyTab: tab }, '', tabHref(tab));
            }
            // Anything holding a direct reference to the old DOM re-initialises.
            $(document).trigger('qly:panel', [$panel.get(0)]);
            // Snapshot after init, so widget setup is not mistaken for an edit.
            baseline = null;
            setTimeout(function () { baseline = snapshot(); }, 0);
            // A tall previous tab can leave the viewport below the new panel.
            if ($(window).scrollTop() > $panel.offset().top) {
                $('html, body').animate({ scrollTop: Math.max(0, $panel.offset().top - 60) }, 150);
            }
        }).fail(function () {
            window.location.href = tabHref(tab);
        }).always(function () {
            loading = false;
            $panel.removeClass('is-loading');
        });
    }

    function tabHref(tab) {
        var $a = $nav.find('.nav-tab[data-tab="' + tab + '"]');
        return $a.length ? $a.attr('href') : window.location.href;
    }

    $(document).on('click', '.qly-settings .nav-tab-wrapper .nav-tab', function (e) {
        // Leave modified clicks alone — they mean "open somewhere else".
        if (e.which > 1 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        var tab = $(this).data('tab');
        if (!tab || !$panel.length) { return; }
        if ($(this).hasClass('nav-tab-active')) { e.preventDefault(); return; }
        if (isDirty() && !window.confirm(
            (estitofoAdmin.i18n && estitofoAdmin.i18n.unsaved) ||
            'You have unsaved changes on this tab. Leave without saving?'
        )) {
            e.preventDefault();
            return;
        }
        e.preventDefault();
        showTab(tab, true);
    });

    // Back / forward through the tabs the switch pushed.
    $(window).on('popstate', function (e) {
        var st = e.originalEvent && e.originalEvent.state;
        if (st && st.qlyTab) { showTab(st.qlyTab, false); }
    });

    // Baseline for the tab the page loaded on, taken once widgets have settled.
    setTimeout(function () { baseline = snapshot(); }, 0);
});
