/* global jQuery, estitofoData, window */
jQuery(function ($) {
    'use strict';

    var i18n = (typeof estitofoData !== 'undefined' && estitofoData.i18n) || {};

    var estimationTool = {
        products: [],
        currencySymbol: '',
        currencyPos: 'left',
        userInfo: null,
        iti: null,
        utilsReady: false,
        activeCategory: 0,

        STORAGE_KEY: 'estitofo_cart_v1',
        STORAGE_TTL_MS: 7 * 24 * 60 * 60 * 1000, // 7 days

        init: function () {
            this._phoneDebug = /[?&]wcestphonedebug=1\b/.test(window.location.search);
            this.getCurrencySettings();
            this.cacheElements();
            this.loadFromStorage();
            this.bindEvents();
            this.renderEstimation();
            this.initPhoneInput();
            this.restoreContactFields();
            this.loadCategories();
            this.loadFeatured();
            this.maybeResume();
        },

        /* ---------- LocalStorage persistence ---------- */
        loadFromStorage: function () {
            try {
                var raw = window.localStorage.getItem(this.STORAGE_KEY);
                if (!raw) return;
                var data = JSON.parse(raw);
                if (!data || typeof data !== 'object') return;
                if (data.savedAt && (Date.now() - data.savedAt) > this.STORAGE_TTL_MS) {
                    window.localStorage.removeItem(this.STORAGE_KEY);
                    return;
                }
                if (Array.isArray(data.products)) {
                    this.products = data.products.filter(function (p) {
                        return p && typeof p === 'object' && p.id;
                    }).map(function (p) {
                        return {
                            id: parseInt(p.id, 10) || 0,
                            title: String(p.title || ''),
                            price: parseFloat(p.price) || 0,
                            image: String(p.image || ''),
                            quantity: Math.max(1, parseInt(p.quantity, 10) || 1),
                            note: String(p.note || '')
                        };
                    });
                }
                this._pendingContact = data.contact || null;
            } catch (e) { /* storage may be disabled */ }
        },

        restoreContactFields: function () {
            if (!this._pendingContact || !this.$form || !this.$form.length) return;
            var c = this._pendingContact;
            if (c.name)    this.$form.find('#estimation-name').val(c.name);
            if (c.email)   this.$form.find('#estimation-email').val(c.email);
            if (c.phone)   this.$form.find('#estimation-phone').val(c.phone);
            if (c.company) this.$form.find('#estimation-company').val(c.company);
            if (c.address) this.$form.find('#estimation-address').val(c.address);
            if (c.notes)   this.$form.find('#estimation-notes').val(c.notes);
            this._pendingContact = null;
        },

        saveToStorage: function () {
            try {
                var contact = null;
                if (this.$form && this.$form.length) {
                    contact = {
                        name:    this.$form.find('#estimation-name').val() || '',
                        email:   this.$form.find('#estimation-email').val() || '',
                        phone:   this.$form.find('#estimation-phone').val() || '',
                        company: this.$form.find('#estimation-company').val() || '',
                        address: this.$form.find('#estimation-address').val() || '',
                        notes:   this.$form.find('#estimation-notes').val() || ''
                    };
                }
                window.localStorage.setItem(this.STORAGE_KEY, JSON.stringify({
                    products: this.products,
                    contact: contact,
                    savedAt: Date.now()
                }));
            } catch (e) { /* storage may be disabled */ }
        },

        clearStorage: function () {
            try { window.localStorage.removeItem(this.STORAGE_KEY); } catch (e) {}
        },

        cacheElements: function () {
            this.$wrapper        = $('.wc-estimation-wrapper');
            this.$categories     = this.$wrapper.find('.wc-est-categories');
            this.$searchInput    = $('.wc-search-input');
            this.$searchResults  = $('.wc-search-results');
            this.$estimationList = $('.wc-estimation-list');
            this.$estimationTotal = $('.wc-estimation-total');
            this.$initiateBtn    = $('#initiate-estimation-btn');
            this.$formContainer  = $('#estimation-form-container');
            this.$form           = $('#estimation-form');
            this.$count          = this.$wrapper.find('.wc-est-count');
            this.$itemsMeta      = this.$wrapper.find('.wc-est-summary-meta__items');
            this.$stepper        = this.$wrapper.find('.wc-est-stepper');
            this.$toastHost      = this.$wrapper.find('.wc-est-toast-host');
            this.$featured       = this.$wrapper.find('.wc-est-featured');
            this.$featuredGrid   = this.$wrapper.find('.wc-est-featured-grid');
            this.$addMoreBtn     = $('<button type="button" class="wc-add-more-btn"></button>').text(i18n.add_more || 'Add More Products');
            this.$estimationList.after(this.$addMoreBtn);
            this.$suggestedProducts = $('<div class="wc-suggested-products" style="display:none;"></div>');
            this.$wrapper.find('.wc-est-search-card').append(this.$suggestedProducts);
            this._totalDisplay = 0; // current displayed total (for animated counter)
        },

        bindEvents: function () {
            var debouncedSearch = this.debounce(this.handleSearch.bind(this), 220);
            this.$searchInput.on('input', debouncedSearch);
            this.$searchInput.on('focus', this.handleSearch.bind(this));
            $(document).on('click', this.handleDocumentClick.bind(this));
            this.$initiateBtn.on('click', this.showEstimationForm.bind(this));
            $(document).on('change', '.product-quantity', this.updateQuantity.bind(this));
            $(document).on('input', '.product-note', this.updateNote.bind(this));
            $(document).on('click', '.remove-product-btn', this.removeProduct.bind(this));
            $(document).on('click', '.quantity-minus', this.decreaseQuantity.bind(this));
            $(document).on('click', '.quantity-plus',  this.increaseQuantity.bind(this));
            $(document).on('click', '.wc-estimation-item-img', this.openLightbox.bind(this));
            $(document).on('click', '.wc-search-result-item', this.addProduct.bind(this));
            $(document).on('click', '.wc-est-cat', this.handleCategoryClick.bind(this));
            $(document).on('click', '#wc-est-save-resume', this.handleSaveResume.bind(this));
            this.$form.on('submit', this.handleFormSubmit.bind(this));
            this.$addMoreBtn.on('click', this.handleAddMore.bind(this));

            // Persist form-field edits so a refresh doesn't lose contact info either.
            var saveContactDebounced = this.debounce(this.saveToStorage.bind(this), 400);
            this.$form.on('input change', 'input, textarea', saveContactDebounced);
        },

        getCurrencySettings: function () {
            this.currencySymbol = estitofoData.currency_symbol || '';
            this.currencyPos    = estitofoData.currency_pos || 'left';
        },

        initPhoneInput: function () {
            var phoneInput = this.$form.find('#estimation-phone').get(0);
            if (!phoneInput || typeof window.intlTelInput !== 'function') {
                return;
            }
            var self = this;
            var defaultCountry = (estitofoData.default_country || '').toLowerCase();
            var restrict       = (estitofoData.restrict_country || '').toLowerCase();

            // utils.js is pre-enqueued by WP (see PHP enqueue_assets), so the
            // global should already be defined here. We don't pass `utilsScript`
            // so intl-tel-input doesn't try to lazy-load it again.
            //
            // nationalMode: true → users type their local format (with leading
            // zero etc.) and libphonenumber handles it correctly. This is the
            // intl-tel-input default and matches how real people enter numbers.
            var options = {
                initialCountry: defaultCountry || 'auto',
                nationalMode: true,
                separateDialCode: true,
                allowDropdown: !restrict,
                autoPlaceholder: 'polite',
                geoIpLookup: function (callback) { callback(defaultCountry || 'us'); }
            };
            if (restrict) {
                options.onlyCountries = [restrict];
                options.initialCountry = restrict;
                options.allowDropdown = false;
            }

            this.iti = window.intlTelInput(phoneInput, options);
            this.$form.find('.form-group').eq(2).append('<div class="validation-message"></div>');

            // Now that utils.js is pre-enqueued, the global should be ready
            // immediately. If it's still missing (some hardened host stripped
            // the script tag) we poll briefly, then fall back to a sensible
            // length-based validator so the form is never permanently blocked.
            this.utilsReady = (typeof window.intlTelInputUtils !== 'undefined');
            if (!this.utilsReady) {
                var tries = 0;
                var poll = setInterval(function () {
                    tries++;
                    if (typeof window.intlTelInputUtils !== 'undefined') {
                        self.utilsReady = true;
                        clearInterval(poll);
                        self.validatePhoneNumber();
                    } else if (tries >= 20) {
                        // ~3 seconds elapsed — give up and accept length-only.
                        clearInterval(poll);
                        self.utilsFailed = true;
                        self.validatePhoneNumber();
                    }
                }, 150);
            }

            // Country-aware validation triggers:
            //   - typing
            //   - leaving the field (blur)
            //   - picking a different country from the flag dropdown
            $(phoneInput).on('input blur', function () { self.validatePhoneNumber(); });
            $(phoneInput).on('countrychange', function () {
                self.validatePhoneNumber();
                // Also force the input to revalidate the placeholder for the new country.
                if (self.iti && typeof self.iti.setNumber === 'function') {
                    try { self.iti.setNumber(self.iti.getNumber()); } catch (e) {}
                }
            });
        },

        /**
         * Country-aware phone validation.
         *
         * When intl-tel-input's utils.js has loaded (`window.intlTelInputUtils`),
         * we use the strict `isValidNumber()` + `getValidationError()` against
         * the *currently selected country* from the flag dropdown. So switching
         * the flag re-runs validation against that country's format.
         *
         * If utils.js hasn't loaded yet (slow network, hardened hosts that
         * block external resources), we fall back to a length-only sanity
         * check so the form is never permanently blocked.
         *
         * @return {boolean} true if the field is acceptable for submission.
         */
        validatePhoneNumber: function () {
            var phoneInput = this.$form.find('#estimation-phone').get(0);
            if (!phoneInput || !this.iti) return true;
            var $msg = $(phoneInput).closest('.form-group').find('.validation-message');
            var val = ($(phoneInput).val() || '').trim();
            if (val === '') {
                $msg.text('').removeClass('valid invalid');
                $(phoneInput).removeClass('valid error');
                this.updateSubmitState(true);
                return true;
            }

            var country = null;
            try { country = this.iti.getSelectedCountryData(); } catch (e) {}
            var countryLabel    = country && country.name ? country.name : '';
            var countryIso      = country && country.iso2 ? String(country.iso2).toLowerCase() : '';
            var countryDial     = country && country.dialCode ? String(country.dialCode) : '';

            // ---------- STRICT path: utils.js loaded ----------
            // libphonenumber is the source of truth. We try TWO inputs:
            //   (a) the raw input as typed
            //   (b) the same with leading zero stripped (national-prefix form)
            // — both validated against the SELECTED country only. We do NOT
            // probe other countries (that was the cause of false-positives
            // where invalid input matched a different country's pattern).
            if (this.utilsReady && typeof window.intlTelInputUtils !== 'undefined') {
                var utils = window.intlTelInputUtils;
                var iso = (countryIso || '').toUpperCase();
                var inputs = [val];
                var digitsOnly = val.replace(/[^0-9]/g, '');
                // National-prefix stripped form (most non-NANPA countries use
                // a leading 0 as the trunk prefix).
                if (digitsOnly.length > 1 && digitsOnly.charAt(0) === '0') {
                    inputs.push(digitsOnly.replace(/^0+/, ''));
                }
                // The full E.164 from intl-tel-input (already normalised).
                try {
                    var e164 = this.iti.getNumber && this.iti.getNumber();
                    if (e164 && inputs.indexOf(e164) < 0) inputs.push(e164);
                } catch (e) {}

                var tries = [];
                var valid = false;
                for (var i = 0; i < inputs.length && !valid; i++) {
                    var cand = inputs[i];
                    if (typeof utils.isValidNumber === 'function') {
                        var r = false;
                        try { r = utils.isValidNumber(cand, iso) === true; } catch (e) {}
                        tries.push('isValidNumber(' + JSON.stringify(cand) + ', ' + iso + ') = ' + r);
                        if (r) valid = true;
                    }
                }
                // Fall back to iti's wrapper if utils direct calls all failed.
                if (!valid) {
                    try {
                        var w = this.iti.isValidNumber();
                        tries.push('iti.isValidNumber() = ' + w);
                        if (w === true) valid = true;
                    } catch (e) {}
                }

                if (this._phoneDebug && window.console && console.log) {
                    /* eslint-disable no-console */
                    console.log('[wc-estimation phone]', {
                        input: val, country: { iso: countryIso, dial: countryDial, label: countryLabel },
                        valid: valid, tries: tries
                    });
                    /* eslint-enable no-console */
                }

                if (valid) {
                    var ok = countryLabel
                        ? (i18n.valid_number_country || 'Valid %s number').replace('%s', countryLabel)
                        : (i18n.valid_number || 'Valid number');
                    $msg.text(ok).removeClass('invalid').addClass('valid');
                    $(phoneInput).removeClass('error').addClass('valid');
                    this.updateSubmitState(true);
                    return true;
                }

                var errCode = -1;
                try { errCode = this.iti.getValidationError(); } catch (e) {}
                $msg.text(this.phoneErrorMessage(errCode, countryLabel))
                    .removeClass('valid').addClass('invalid');
                $(phoneInput).removeClass('valid').addClass('error');
                this.updateSubmitState(false);
                return false;
            }

            // ---------- utils.js permanently unavailable ----------
            // The host blocked / stripped utils.js after 3s of polling.
            // Fall back to a length-based validator so the form is still usable.
            if (this.utilsFailed) {
                var digits = val.replace(/\D/g, '');
                if (digits.length >= 7 && digits.length <= 15) {
                    $msg.text('').removeClass('valid invalid');
                    $(phoneInput).removeClass('valid error');
                    this.updateSubmitState(true);
                    return true;
                }
                $msg.text(i18n.invalid_phone || 'Please enter a valid phone number.').removeClass('valid').addClass('invalid');
                $(phoneInput).removeClass('valid').addClass('error');
                this.updateSubmitState(false);
                return false;
            }

            // ---------- utils.js still loading ----------
            $msg.text(i18n.phone_loading || 'Checking phone number…').removeClass('valid invalid');
            $(phoneInput).removeClass('valid error');
            this.updateSubmitState(false);
            return false;
        },

        /**
         * Reflect phone validity on the submit button. When the phone is
         * invalid we visually disable the button so the user can see the
         * form isn't ready to send.
         */
        updateSubmitState: function (phoneValid) {
            if (!this.$form || !this.$form.length) return;
            var $btn = this.$form.find('button[type=submit]');
            if (!$btn.length) return;
            // We only toggle a class — keep the button enabled so the user
            // can click it and see the validation message bubble up.
            if (phoneValid) {
                $btn.removeClass('is-disabled');
            } else {
                $btn.addClass('is-disabled');
            }
        },

        /**
         * Map intl-tel-input's getValidationError() codes to user-facing strings.
         * Codes: 0 IS_POSSIBLE, 1 INVALID_COUNTRY_CODE, 2 TOO_SHORT, 3 TOO_LONG, 4 NOT_A_NUMBER, 5 INVALID_LENGTH
         */
        phoneErrorMessage: function (code, country) {
            var t = i18n || {};
            switch (code) {
                case 1:
                    return t.phone_invalid_country_code || 'Invalid country dial code. Please pick a country from the dropdown.';
                case 2:
                    return country
                        ? (t.phone_too_short_country || 'Number is too short for %s.').replace('%s', country)
                        : (t.phone_too_short || 'Phone number is too short.');
                case 3:
                    return country
                        ? (t.phone_too_long_country || 'Number is too long for %s.').replace('%s', country)
                        : (t.phone_too_long || 'Phone number is too long.');
                case 4:
                    return t.phone_not_a_number || 'Please enter digits only.';
                default:
                    return country
                        ? (t.phone_invalid_for_country || "Doesn't look like a valid %s number.").replace('%s', country)
                        : (t.invalid_phone || 'Please enter a valid phone number.');
            }
        },

        /* ---------- Category chips ---------- */
        loadCategories: function () {
            if (!this.$categories.length) return;
            var self = this;
            $.ajax({
                url: estitofoData.rest_url + 'categories',
                type: 'GET'
            }).done(function (cats) {
                if (!cats || !cats.length) return;
                var $all = $('<button type="button" class="wc-est-cat active" data-id="0"></button>').text(i18n.all || 'All');
                self.$categories.empty().append($all);
                cats.forEach(function (c) {
                    var $b = $('<button type="button" class="wc-est-cat"></button>').attr('data-id', c.id).text(c.name);
                    self.$categories.append($b);
                });
            });
        },

        handleCategoryClick: function (e) {
            var $btn = $(e.currentTarget);
            this.activeCategory = parseInt($btn.data('id'), 10) || 0;
            this.$categories.find('.wc-est-cat').removeClass('active');
            $btn.addClass('active');
            if (this.$searchInput.val().length >= 2) {
                this.handleSearch();
            }
        },

        /* ---------- Featured / popular products grid ---------- */
        loadFeatured: function () {
            if (!this.$featured || !this.$featured.length) return;
            // Don't show featured grid if cart already has products.
            if (this.products.length > 0) {
                this.$featured.hide();
                return;
            }
            var self = this;
            $.ajax({
                url: estitofoData.rest_url + 'suggested',
                type: 'GET',
                dataType: 'json'
            }).done(function (data) {
                if (!Array.isArray(data) || !data.length) {
                    self.$featured.hide();
                    return;
                }
                self.renderFeatured(data);
            }).fail(function () {
                self.$featured.hide();
            });
        },

        renderFeatured: function (products) {
            this.$featuredGrid.empty();
            var self = this;
            products.forEach(function (p) {
                var $card = $('<button type="button" class="wc-est-featured-card"></button>')
                    .attr('data-id', p.id)
                    .attr('aria-label', (i18n.add_to_estimation || 'Add to estimation') + ': ' + p.title);
                var $imgWrap = $('<div class="wc-est-featured-card__img"></div>');
                if (p.image) {
                    $imgWrap.append($('<img alt=""/>').attr('src', p.image));
                } else {
                    $imgWrap.addClass('is-placeholder');
                }
                var $info = $('<div class="wc-est-featured-card__info"></div>');
                $info.append($('<div class="wc-est-featured-card__title"></div>').text(p.title));
                $info.append($('<div class="wc-est-featured-card__price"></div>').html(p.price_html));
                var $add = $('<span class="wc-est-featured-card__add" aria-hidden="true">+</span>');
                $card.append($imgWrap).append($info).append($add);
                $card.on('click', function () {
                    if (self.products.some(function (q) { return q.id === p.id; })) {
                        self.showToast(i18n.already_added || 'Already in your estimation', 'warning');
                        return;
                    }
                    self.products.push({ id: p.id, title: p.title, price: p.price, image: p.image, quantity: 1, note: '' });
                    self.saveToStorage();
                    self.renderEstimation();
                    self.showToast((i18n.added_to_cart || 'Added') + ': ' + p.title, 'success');
                });
                self.$featuredGrid.append($card);
            });
            this.$featured.show();
        },

        toggleFeatured: function () {
            if (!this.$featured || !this.$featured.length) return;
            // Hide featured grid once cart has items; show again when emptied.
            if (this.products.length > 0) {
                this.$featured.slideUp(180);
            } else if (this.$featuredGrid.children().length > 0) {
                this.$featured.slideDown(180);
            }
        },

        /* ---------- Animated total counter ---------- */
        animateTotal: function (target) {
            var self = this;
            var start = this._totalDisplay || 0;
            var diff = target - start;
            if (Math.abs(diff) < 0.01) {
                this._totalDisplay = target;
                this.$estimationTotal.text(this.formatPrice(target));
                return;
            }
            var duration = 320;
            var startTime = performance && performance.now ? performance.now() : Date.now();
            var rafSupported = typeof window.requestAnimationFrame === 'function';
            if (this._totalRaf && rafSupported) cancelAnimationFrame(this._totalRaf);

            var step = function (now) {
                var t = Math.min(1, ((performance && performance.now ? performance.now() : Date.now()) - startTime) / duration);
                // ease-out cubic
                var eased = 1 - Math.pow(1 - t, 3);
                var value = start + diff * eased;
                self._totalDisplay = value;
                self.$estimationTotal.text(self.formatPrice(value));
                if (t < 1 && rafSupported) {
                    self._totalRaf = requestAnimationFrame(step);
                } else {
                    self._totalDisplay = target;
                    self.$estimationTotal.text(self.formatPrice(target));
                }
            };
            if (rafSupported) {
                this._totalRaf = requestAnimationFrame(step);
            } else {
                this._totalDisplay = target;
                this.$estimationTotal.text(this.formatPrice(target));
            }
        },

        /* ---------- Search ---------- */
        handleSearch: function () {
            var term = (this.$searchInput.val() || '').trim();
            this.$suggestedProducts.hide();
            if (term.length < 2) {
                this.$searchResults.hide().empty();
                return;
            }
            var self = this;
            this.$searchResults.empty().append(
                $('<div class="wc-search-loading"></div>').text(i18n.loading_suggested || 'Loading…')
            ).show();

            // Prefer REST, fall back to admin-ajax (more permissive on hardened hosts).
            var restRequest = $.ajax({
                url: estitofoData.rest_url + 'search',
                data: { term: term, category: this.activeCategory },
                type: 'GET',
                dataType: 'json'
            });

            restRequest.done(function (results) {
                if (Array.isArray(results) && results.length) {
                    self.displaySearchResults(results);
                } else {
                    self.fallbackSearch(term);
                }
            }).fail(function () {
                self.fallbackSearch(term);
            });
        },

        fallbackSearch: function (term) {
            var self = this;
            $.ajax({
                url: estitofoData.ajax_url,
                type: 'GET',
                dataType: 'json',
                data: {
                    action: 'estitofo_search_products',
                    nonce: estitofoData.nonce,
                    term: term,
                    category: this.activeCategory
                }
            }).done(function (resp) {
                var list = (resp && resp.success && Array.isArray(resp.data)) ? resp.data : [];
                self.displaySearchResults(list);
            }).fail(function () {
                self.displaySearchResults([]);
            });
        },

        displaySearchResults: function (products) {
            this.$searchResults.empty();
            if (!products.length) {
                this.$searchResults.append(
                    $('<div class="wc-search-empty"></div>').text(i18n.no_results || 'No products match your search.')
                ).show();
                return;
            }
            var self = this;
            products.forEach(function (p) {
                var $row = $('<div class="wc-search-result-item"></div>')
                    .attr('data-id', p.id)
                    .attr('data-price', p.price);
                if (p.image) $row.append($('<img/>', { 'class': 'wc-search-result-img', src: p.image, alt: '' }));
                var $info = $('<div class="wc-search-result-info"></div>');
                $info.append($('<div class="wc-result-title"></div>').text(p.title));
                $info.append($('<div class="wc-result-price"></div>').html(p.price_html));
                $row.append($info);
                $row.append($('<div class="wc-add-to-estimation"></div>').text('+'));
                self.$searchResults.append($row);
            });
            this.$searchResults.show();
        },

        handleDocumentClick: function (e) {
            var $target = $(e.target);
            if ($target.closest('.wc-search-container').length || $target.closest('.wc-search-results').length) {
                return;
            }
            this.$searchResults.hide();
        },

        debounce: function (fn, wait) {
            var t;
            return function () {
                var ctx = this, args = arguments;
                clearTimeout(t);
                t = setTimeout(function () { fn.apply(ctx, args); }, wait);
            };
        },

        handleAddMore: function () {
            this.$searchInput.trigger('focus');
            this.loadSuggestedProducts();
        },

        loadSuggestedProducts: function () {
            var self = this;
            this.$suggestedProducts.empty().append($('<div class="loading"></div>').text(i18n.loading_suggested || 'Loading...')).show();
            $.ajax({
                url: estitofoData.rest_url + 'suggested',
                type: 'GET'
            }).done(function (data) {
                if (!data || !data.length) {
                    self.$suggestedProducts.empty().append($('<div/>').text(i18n.no_suggested || 'No suggestions.')).show();
                    return;
                }
                self.$suggestedProducts.empty();
                data.forEach(function (p) {
                    var $b = $('<div class="wc-suggested-product"></div>').attr('data-id', p.id).text(p.title);
                    $b.on('click', function () {
                        if (self.products.some(function (q) { return q.id === p.id; })) {
                            self.showInlineMessage(i18n.already_added);
                            return;
                        }
                        self.products.push({ id: p.id, title: p.title, price: p.price, image: p.image, quantity: 1, note: '' });
                        self.saveToStorage();
                        self.renderEstimation();
                        self.$suggestedProducts.empty().hide();
                    });
                    self.$suggestedProducts.append($b);
                });
                self.$suggestedProducts.show();
            });
        },

        /* ---------- Selected products ---------- */
        addProduct: function (e) {
            var $item = $(e.target).closest('.wc-search-result-item');
            var productId = parseInt($item.data('id'), 10);
            if (!productId) return;
            if (this.products.some(function (p) { return p.id === productId; })) {
                this.showInlineMessage(i18n.already_added);
                return;
            }
            var priceValue = parseFloat($item.data('price')) || 0;
            var title = $item.find('.wc-result-title').text();
            this.products.push({
                id: productId,
                title: title,
                price: priceValue,
                image: $item.find('.wc-search-result-img').attr('src'),
                quantity: 1,
                note: ''
            });
            this.saveToStorage();
            this.renderEstimation();
            this.$searchInput.val('').trigger('input');
            this.$searchResults.hide();
            this.$suggestedProducts.hide();
            this.showToast(
                (i18n.added_to_cart || 'Added') + ': ' + title,
                'success'
            );
        },

        renderEstimation: function () {
            this.$estimationList.empty();

            if (this.products.length === 0) {
                // Pretty empty-state card (matches the server-rendered initial state).
                var $empty = $('<div class="wc-est-empty"></div>');
                $empty.append(
                    '<svg class="wc-est-empty__icon" viewBox="0 0 64 64" fill="none" aria-hidden="true">' +
                        '<rect x="12" y="14" width="40" height="44" rx="4" stroke="currentColor" stroke-width="2.5" fill="none"/>' +
                        '<path d="M22 8h20a2 2 0 0 1 2 2v6H20v-6a2 2 0 0 1 2-2z" stroke="currentColor" stroke-width="2.5" fill="none"/>' +
                        '<path d="M22 30h20M22 38h20M22 46h12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>' +
                    '</svg>'
                );
                $empty.append($('<p class="wc-est-empty__title"></p>').text(i18n.no_products || 'No products added yet'));
                $empty.append($('<p class="wc-est-empty__hint"></p>').text(i18n.empty_hint || 'Search above to add products to your estimation.'));
                this.$estimationList.append($empty);
                this.animateTotal(0);
                this.$initiateBtn.hide();
                this.$formContainer.removeClass('visible').hide();
                this.$addMoreBtn.hide();
                this.updateChrome(0, 0);
                this.toggleFeatured();
                return;
            }

            var $table = $('<table class="wc-estimation-table"></table>');
            var $thead = $('<thead><tr></tr></thead>');
            ['product', 'price', 'quantity', 'subtotal', 'action'].forEach(function (k) {
                $thead.find('tr').append($('<th/>').text(i18n[k] || k));
            });
            var $tbody = $('<tbody></tbody>');
            $table.append($thead).append($tbody);
            this.$estimationList.append($table);

            var total = 0;
            var self = this;
            this.products.forEach(function (p) {
                var subtotal = p.price * p.quantity;
                total += subtotal;
                var $row = $('<tr class="wc-estimation-item"></tr>').attr('data-id', p.id);

                var $td1 = $('<td class="pimagetitle"></td>');
                if (p.image) $td1.append($('<img/>', { 'class': 'wc-estimation-item-img', src: p.image, alt: '' }));
                var $titleBlock = $('<div></div>');
                $titleBlock.append($('<div></div>').text(p.title));
                $titleBlock.append($('<textarea class="product-note" rows="1"></textarea>')
                    .attr('placeholder', i18n.add_note || 'Add note…')
                    .val(p.note || ''));
                $td1.append($titleBlock);
                $row.append($td1);

                $row.append($('<td class="product-price"></td>').text(self.formatPrice(p.price)));

                var $qtyTd = $('<td></td>');
                var $controls = $('<div class="quantity-controls"></div>');
                $controls.append($('<button type="button" class="quantity-minus">−</button>'));
                $controls.append($('<input type="number" class="product-quantity" min="1">').val(p.quantity));
                $controls.append($('<button type="button" class="quantity-plus">+</button>'));
                $qtyTd.append($controls);
                $row.append($qtyTd);

                $row.append($('<td class="product-subtotal"></td>').text(self.formatPrice(subtotal)));

                var $actionTd = $('<td></td>');
                $actionTd.append($('<button type="button" class="remove-product-btn" aria-label="remove">×</button>'));
                $row.append($actionTd);

                $tbody.append($row);
            });

            this.animateTotal(total);
            if (!this.$formContainer.is(':visible')) this.$initiateBtn.show();
            this.$addMoreBtn.show();
            this.updateChrome(this.products.length, total);
            this.toggleFeatured();
        },

        /**
         * Update the various chrome elements that mirror cart state:
         * count badge, items meta line, wrapper class (sticky mobile bar),
         * and stepper "Browse" step status.
         */
        updateChrome: function (count, total) {
            if (this.$count && this.$count.length) {
                this.$count.text(String(count)).toggleClass('is-empty', count === 0);
            }
            if (this.$itemsMeta && this.$itemsMeta.length) {
                this.$itemsMeta.text(count + ' ' + (count === 1
                    ? (i18n.item || 'item')
                    : (i18n.items || 'items')));
            }
            this.$wrapper.toggleClass('has-products', count > 0);
            if (count > 0 && this.$stepper && this.$stepper.length) {
                // "Browse" is done once at least one product is in the cart, but stays
                // active until the form is opened — keep the first step highlighted.
                this.$stepper.find('.wc-est-stepper__item').removeClass('is-done');
                this.$stepper.find('[data-step="1"]').addClass('is-active');
            }
        },

        /**
         * Move the visual stepper to a given step (1-Browse, 2-Contact, 3-Done).
         */
        setStep: function (step) {
            if (!this.$stepper || !this.$stepper.length) return;
            var $items = this.$stepper.find('.wc-est-stepper__item');
            $items.each(function (i) {
                var n = i + 1;
                $(this).toggleClass('is-active', n === step)
                       .toggleClass('is-done', n < step);
            });
        },

        /**
         * Pure-CSS confetti burst — small celebration when the estimation
         * is submitted successfully. No external library, ~40 short-lived
         * elements that animate via CSS @keyframes and self-clean.
         */
        confettiBurst: function () {
            var host = document.createElement('div');
            host.className = 'wc-est-confetti';
            host.setAttribute('aria-hidden', 'true');
            var colors = ['#2563eb', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', '#06b6d4'];
            for (var i = 0; i < 36; i++) {
                var piece = document.createElement('span');
                piece.className = 'wc-est-confetti__piece';
                piece.style.left = (Math.random() * 100) + '%';
                piece.style.background = colors[i % colors.length];
                piece.style.animationDelay = (Math.random() * 0.2) + 's';
                piece.style.animationDuration = (1.5 + Math.random()) + 's';
                piece.style.transform = 'rotate(' + (Math.random() * 360) + 'deg)';
                host.appendChild(piece);
            }
            (this.$wrapper.get(0) || document.body).appendChild(host);
            setTimeout(function () { if (host.parentNode) host.parentNode.removeChild(host); }, 3000);
        },

        /**
         * Lightweight toast notification (top-level, replaces the old
         * inline yellow message bar).
         */
        showToast: function (msg, kind) {
            if (!msg) return;
            if (!this.$toastHost || !this.$toastHost.length) {
                this.showInlineMessage(msg); // fallback
                return;
            }
            var $t = $('<div class="wc-est-toast"></div>').text(msg);
            if (kind) $t.addClass('is-' + kind);
            this.$toastHost.append($t);
            setTimeout(function () {
                $t.addClass('is-leaving');
                setTimeout(function () { $t.remove(); }, 280);
            }, 2400);
        },

        decreaseQuantity: function (e) {
            var $i = $(e.target).closest('.quantity-controls').find('.product-quantity');
            var v = parseInt($i.val(), 10) || 1;
            if (v > 1) $i.val(v - 1).trigger('change');
        },
        increaseQuantity: function (e) {
            var $i = $(e.target).closest('.quantity-controls').find('.product-quantity');
            var v = parseInt($i.val(), 10) || 1;
            $i.val(v + 1).trigger('change');
        },
        updateQuantity: function (e) {
            var $i = $(e.target);
            var id = parseInt($i.closest('.wc-estimation-item').data('id'), 10);
            var q = Math.max(1, parseInt($i.val(), 10) || 1);
            var p = this.products.find(function (pr) { return pr.id === id; });
            if (p) {
                p.quantity = q;
                $i.closest('tr').find('.product-subtotal').text(this.formatPrice(p.price * q));
                this.updateTotal();
                this.saveToStorage();
            }
        },
        updateNote: function (e) {
            var $i = $(e.target);
            var id = parseInt($i.closest('.wc-estimation-item').data('id'), 10);
            var p = this.products.find(function (pr) { return pr.id === id; });
            if (p) {
                p.note = $i.val();
                this.saveToStorage();
            }
        },
        removeProduct: function (e) {
            var $row = $(e.target).closest('.wc-estimation-item');
            var id = parseInt($row.data('id'), 10);
            this.products = this.products.filter(function (p) { return p.id !== id; });
            $row.remove();
            this.updateTotal();
            this.saveToStorage();
            if (this.products.length === 0) {
                this.$formContainer.hide();
                this.$initiateBtn.hide();
                this.$addMoreBtn.hide();
                this.renderEstimation();
            }
        },
        updateTotal: function () {
            var total = this.products.reduce(function (s, p) { return s + p.price * p.quantity; }, 0);
            this.animateTotal(total);
            this.updateChrome(this.products.length, total);
        },

        /* ---------- Lightbox ---------- */
        openLightbox: function (e) {
            var src = $(e.target).attr('src');
            if (!src) return;
            var $lb = $('<div class="wc-est-lightbox"></div>');
            $lb.append($('<img alt=""/>').attr('src', src));
            $lb.on('click', function () { $lb.remove(); });
            $('body').append($lb);
        },

        /* ---------- Form / submit ---------- */
        showEstimationForm: function () {
            if (this.products.length === 0) return;
            this.$initiateBtn.hide();
            this.$formContainer.addClass('visible').slideDown(200);
            this.setStep(2);
            // Scroll the form into view on mobile so the user sees what just appeared.
            var self = this;
            setTimeout(function () {
                var form = self.$form.get(0);
                if (form && typeof form.scrollIntoView === 'function') {
                    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }, 220);
        },

        showInlineMessage: function (msg) {
            // Kept for backwards compatibility; routes to toast when available.
            if (this.$toastHost && this.$toastHost.length) {
                this.showToast(msg);
                return;
            }
            var $m = $('<div class="wc-estimation-inline-msg"></div>').text(msg || '');
            this.$estimationList.before($m);
            setTimeout(function () { $m.fadeOut(400, function () { $(this).remove(); }); }, 2500);
        },

        handleFormSubmit: function (e) {
            e.preventDefault();
            var $btn = this.$form.find('button[type=submit]');
            $btn.prop('disabled', true);

            var name  = this.$form.find('#estimation-name').val().trim();
            var email = this.$form.find('#estimation-email').val().trim();
            var phoneInput = this.$form.find('#estimation-phone').get(0);
            var phone = phoneInput ? phoneInput.value.trim() : '';
            var honeypot = this.$form.find('#estimation-website').val();
            if (honeypot) { $btn.prop('disabled', false); return; }

            if (!name || !email || !phone) {
                this.showInlineMessage(i18n.fill_required);
                $btn.prop('disabled', false);
                return;
            }
            if (!this.validatePhoneNumber()) {
                this.showInlineMessage(i18n.invalid_phone);
                $btn.prop('disabled', false);
                return;
            }
            if (this.products.length === 0) {
                this.showInlineMessage(i18n.add_products_first);
                $btn.prop('disabled', false);
                return;
            }

            var formatted = phone;
            try { if (this.iti && this.iti.getNumber) { var n = this.iti.getNumber(); if (n) formatted = n; } } catch (er) {}

            this.userInfo = { name: name, email: email, phone: formatted };
            var total = this.products.reduce(function (s, p) { return s + p.price * p.quantity; }, 0);

            var payload = {
                action: 'estitofo_submit',
                products: JSON.stringify(this.products),
                total: total,
                nonce: estitofoData.nonce,
                name: name,
                email: email,
                phone: formatted,
                company: this.$form.find('#estimation-company').val() || '',
                address: this.$form.find('#estimation-address').val() || '',
                customer_notes: this.$form.find('#estimation-notes').val() || '',
                website: ''
            };

            // Pass through any add-on fields (Pro: conditional fields, file uploads,
            // recaptcha, deposit info, etc.). Excludes the core fields we've
            // already built into the payload above, and skips submit/button inputs.
            this.$form.find('input, select, textarea').each(function () {
                var name = this.name;
                if (!name || payload.hasOwnProperty(name)) return;
                var type = (this.type || '').toLowerCase();
                if (type === 'submit' || type === 'button' || type === 'reset' || type === 'file') return;
                if (type === 'checkbox' || type === 'radio') {
                    if (this.checked) {
                        // For checkbox groups (name[]) collect all values; for radios just take the checked one.
                        if (/\[\]$/.test(name) && payload.hasOwnProperty(name)) {
                            payload[name] = [].concat(payload[name], $(this).val());
                        } else {
                            payload[name] = $(this).val();
                        }
                    }
                    return;
                }
                payload[name] = $(this).val();
            });

            var self = this;
            $.ajax({
                url: estitofoData.ajax_url,
                type: 'POST',
                data: payload,
                beforeSend: function () {
                    self.$form.hide();
                    $('#estimation-success').show().empty()
                        .append($('<p/>').text(i18n.generating || 'Generating your estimation...'))
                        .append($('<div class="loading-spinner"></div>'));
                }
            }).done(function (resp) {
                // wp_send_json_error() replies with HTTP 200, so server-side
                // rejections (missing phone, rate limit, spam) land here too —
                // treat them as failures, never as a successful submission.
                if (!resp || !resp.success) {
                    $('#estimation-success').hide();
                    self.$form.show();
                    $btn.prop('disabled', false);
                    var msg = (resp && typeof resp.data === 'string' && resp.data) ? resp.data : i18n.submission_error;
                    self.showInlineMessage(msg);
                    return;
                }
                self.clearStorage();
                var data = resp.data || {};
                var pdfUrl = data.pdf_url || '';
                if (pdfUrl) {
                    // Auto-download via hidden iframe so the success screen still shows.
                    self.triggerPdfDownload(pdfUrl);
                } else {
                    // Fallback to the old blob endpoint if the server didn't return a URL.
                    self.generatePDF();
                }
                self.renderSuccess(pdfUrl);
            }).fail(function () {
                $('#estimation-success').hide();
                self.$form.show();
                $btn.prop('disabled', false);
                self.showInlineMessage(i18n.submission_error);
            });
        },

        triggerPdfDownload: function (url) {
            if (!url) return;
            // Hidden iframe download keeps the success page visible (no navigation).
            var iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.src = url;
            document.body.appendChild(iframe);
            // Clean up after a delay; browser will already have streamed the file.
            setTimeout(function () {
                if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
            }, 60000);
        },

        renderSuccess: function (pdfUrl) {
            var self = this;
            this.setStep(3);
            this.confettiBurst();
            var total = this.products.reduce(function (s, p) { return s + p.price * p.quantity; }, 0);
            // Prefer the PDF share link; fall back to the current page URL if missing.
            var shareUrl = pdfUrl || window.location.href.split('?')[0];

            var $box = $('<div></div>');
            $box.append($('<p/>').text(i18n.thank_you || 'Thank you!'));

            if (pdfUrl) {
                var $download = $('<a class="wc-est-pdf-link" target="_blank" rel="noopener"></a>')
                    .attr('href', pdfUrl)
                    .text(i18n.download_pdf || 'Download PDF');
                $box.append($download);
            }

            var $share = $('<div class="wc-est-share"></div>');
            var summary = (i18n.share_text || 'My estimation total: ') + this.formatPrice(total);
            var waUrl = 'https://wa.me/?text=' + encodeURIComponent(summary + ' — ' + shareUrl);
            $share.append($('<a class="whatsapp" target="_blank" rel="noopener"></a>').attr('href', waUrl).text('WhatsApp'));
            var $copy = $('<button type="button" class="copy"></button>').text(i18n.copy_link || 'Copy link');
            $copy.on('click', function () {
                var done = function () { $copy.text(i18n.copied || 'Copied'); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(shareUrl).then(done, function () {
                        // fallback to legacy execCommand
                        var ta = document.createElement('textarea');
                        ta.value = shareUrl;
                        document.body.appendChild(ta);
                        ta.select();
                        try { document.execCommand('copy'); done(); } catch (e) {}
                        document.body.removeChild(ta);
                    });
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = shareUrl;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); done(); } catch (e) {}
                    document.body.removeChild(ta);
                }
            });
            $share.append($copy);
            $box.append($share);

            var $btn = $('<button type="button" class="wc-new-estimate-btn"></button>').text(i18n.new_estimation || 'New Estimation');
            $box.append($btn);
            $('#estimation-success').empty().append($box);

            $btn.on('click', function () {
                $('#estimation-success').fadeOut(300, function () {
                    self.$form.fadeIn(300).trigger('reset');
                    self.products = [];
                    self.userInfo = null;
                    self.renderEstimation();
                    self.setStep(1);
                    self.$form.find('button[type=submit]').prop('disabled', false);
                });
            });
        },

        generatePDF: function () {
            if (this.products.length === 0) return;
            var total = this.products.reduce(function (s, p) { return s + p.price * p.quantity; }, 0);
            var data = {
                action: 'estitofo_generate_pdf',
                products: JSON.stringify(this.products),
                total: total,
                currency: this.currencySymbol,
                nonce: estitofoData.nonce
            };
            if (this.userInfo) {
                data.name = this.userInfo.name;
                data.email = this.userInfo.email;
                data.phone = this.userInfo.phone;
            }
            var self = this;
            $.ajax({
                url: estitofoData.ajax_url,
                type: 'POST',
                data: data,
                xhrFields: { responseType: 'blob' }
            }).done(function (blob) {
                var link = document.createElement('a');
                link.href = window.URL.createObjectURL(blob);
                link.download = 'product-estimation-' + new Date().toISOString().slice(0, 10) + '.pdf';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }).fail(function () {
                self.showInlineMessage(i18n.pdf_error);
            });
        },

        /* ---------- Save & resume ---------- */
        handleSaveResume: function () {
            var email = this.$form.find('#estimation-email').val().trim();
            if (!email) {
                this.showInlineMessage(i18n.resume_email_required || 'Please enter your email first.');
                return;
            }
            if (this.products.length === 0) {
                this.showInlineMessage(i18n.add_products_first);
                return;
            }
            var self = this;
            $.post(estitofoData.ajax_url, {
                action: 'estitofo_save_resume',
                nonce: estitofoData.nonce,
                email: email,
                name: this.$form.find('#estimation-name').val() || '',
                phone: this.$form.find('#estimation-phone').val() || '',
                company: this.$form.find('#estimation-company').val() || '',
                address: this.$form.find('#estimation-address').val() || '',
                customer_notes: this.$form.find('#estimation-notes').val() || '',
                products: JSON.stringify(this.products),
                total: this.products.reduce(function (s, p) { return s + p.price * p.quantity; }, 0)
            }, function (r) {
                if (r && r.success) {
                    self.showInlineMessage(r.data && r.data.message ? r.data.message : (i18n.resume_sent || 'Check your inbox.'));
                } else {
                    self.showInlineMessage(i18n.submission_error);
                }
            }, 'json');
        },

        maybeResume: function () {
            var params = new URLSearchParams(window.location.search);
            var token = params.get('estitofo_resume');
            if (!token) return;
            var self = this;
            $.ajax({
                url: estitofoData.rest_url + 'resume/' + encodeURIComponent(token),
                type: 'GET'
            }).done(function (data) {
                if (!data || !data.products) return;
                self.products = data.products.map(function (p) {
                    return {
                        id: parseInt(p.id, 10) || 0,
                        title: p.title || '',
                        price: parseFloat(p.price) || 0,
                        image: p.image || '',
                        quantity: parseInt(p.quantity, 10) || 1,
                        note: p.note || ''
                    };
                });
                self.$form.find('#estimation-name').val(data.name || '');
                self.$form.find('#estimation-email').val(data.email || '');
                self.$form.find('#estimation-phone').val(data.phone || '');
                self.$form.find('#estimation-company').val(data.company || '');
                self.$form.find('#estimation-address').val(data.address || '');
                self.$form.find('#estimation-notes').val(data.customer_notes || '');
                self.renderEstimation();
                self.showInlineMessage(i18n.resume_loaded || 'Estimation restored.');
            });
        },

        /* ---------- Formatting ---------- */
        formatPrice: function (amount) {
            amount = parseFloat(amount) || 0;
            var s = amount.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
            switch (this.currencyPos) {
                case 'right':       return s + this.currencySymbol;
                case 'right_space': return s + ' ' + this.currencySymbol;
                case 'left_space':  return this.currencySymbol + ' ' + s;
                default:            return this.currencySymbol + s;
            }
        }
    };

    estimationTool.init();
});
