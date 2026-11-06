/**
 * Crypto Payments for WooCommerce - Checkout JS
 */
(function ($) {
    'use strict';

    var cpw = {
        timer: null,
        timerSeconds: 0,
        currentNetwork: null,

        init: function () {
            $(document.body).on('change', '#cpw_network', this.onNetworkChange.bind(this));
            $(document.body).on('click', '#cpw-copy-amount', this.copyAmount.bind(this));
            $(document.body).on('click', '#cpw-copy-address', this.copyAddress.bind(this));

            // Reset the button text when user switches away from crypto payment.
            $(document.body).on('change', 'input[name="payment_method"]', function () {
                var selected = $('input[name="payment_method"]:checked').val();
                if (selected !== 'crypto_payments') {
                    cpw.updatePlaceOrderButton(false);
                }
            });

            // Re-init after WooCommerce updates checkout fragments.
            $(document.body).on('updated_checkout', function () {
                cpw.initDropdown();
            });

            this.initDropdown();
        },

        // ── Custom dropdown ──────────────────────────────────────────

        initDropdown: function () {
            var self = this;
            // Leave the block checkout's dropdown to its own script: this one's click handler
            // stops the event, so the block's menu would never open on a mouse click.
            var $dropdown = $('.cpw-dropdown').not('.cpw-block-wrapper .cpw-dropdown');
            if (!$dropdown.length) return;

            var $trigger = $dropdown.find('.cpw-dropdown-trigger');
            var $menu = $dropdown.find('.cpw-dropdown-menu');
            var $options = $menu.find('.cpw-dropdown-option');

            var $search = $menu.find('.cpw-dropdown-search');

            // Toggle on trigger click.
            $trigger.off('click.cpw').on('click.cpw', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var isOpen = $dropdown.attr('aria-expanded') === 'true';
                self.toggleDropdown(!isOpen);
            });

            // Select on option click.
            $options.off('click.cpw').on('click.cpw', function (e) {
                e.preventDefault();
                e.stopPropagation();
                self.selectOption($(this));
            });

            // Search input filtering.
            $search.off('input.cpw').on('input.cpw', function () {
                self.filterOptions($(this).val());
            });

            // Prevent search input from closing dropdown or triggering option keys.
            $search.off('keydown.cpw').on('keydown.cpw', function (e) {
                e.stopPropagation();
                if (e.key === 'Escape') {
                    self.toggleDropdown(false);
                    $trigger.focus();
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    $menu.find('.cpw-dropdown-option:visible').first().focus();
                }
            });

            // Keyboard navigation on trigger.
            $trigger.off('keydown.cpw').on('keydown.cpw', function (e) {
                self.handleTriggerKeydown(e);
            });

            // Keyboard navigation on options.
            $options.off('keydown.cpw').on('keydown.cpw', function (e) {
                self.handleOptionKeydown(e);
            });

            // Close on outside click.
            $(document).off('click.cpw-close').on('click.cpw-close', function (e) {
                if (!$(e.target).closest('.cpw-dropdown').length) {
                    self.toggleDropdown(false);
                }
            });
        },

        toggleDropdown: function (open) {
            var $dropdown = $('.cpw-dropdown');
            var $trigger = $dropdown.find('.cpw-dropdown-trigger');
            var $menu = $dropdown.find('.cpw-dropdown-menu');
            var $search = $menu.find('.cpw-dropdown-search');

            $dropdown.attr('aria-expanded', open ? 'true' : 'false');
            $trigger.attr('aria-expanded', open ? 'true' : 'false');

            if (open) {
                $menu.addClass('cpw-dropdown-menu--open');
                // Clear previous search and focus the search input.
                $search.val('');
                this.filterOptions('');
                $search.focus();
            } else {
                $menu.removeClass('cpw-dropdown-menu--open');
            }
        },

        filterOptions: function (query) {
            var $menu = $('.cpw-dropdown-menu');
            var term = query.toLowerCase().trim();

            $menu.find('.cpw-dropdown-option').each(function () {
                var $opt = $(this);
                var name = $opt.find('.cpw-dropdown-name').text().toLowerCase();
                var value = ($opt.data('value') || '').toLowerCase();
                var symbol = ($opt.data('symbol') || '').toLowerCase();
                var match = !term || name.indexOf(term) > -1 || value.indexOf(term) > -1 || symbol.indexOf(term) > -1;
                $opt.toggle(match);
            });

            // Hide group labels with no visible options.
            $menu.find('.cpw-dropdown-group').each(function () {
                var $group = $(this);
                var hasVisible = $group.find('.cpw-dropdown-option:visible').length > 0;
                $group.toggle(hasVisible);
            });

            // Show "no results" message.
            $menu.find('.cpw-dropdown-no-results').remove();
            if (!$menu.find('.cpw-dropdown-option:visible').length) {
                $menu.append('<div class="cpw-dropdown-no-results">' + cpw_data.i18n_no_results + '</div>');
            }
        },

        selectOption: function ($opt) {
            var value = $opt.data('value');
            var name = $opt.find('.cpw-dropdown-name').text();
            var svg = $opt.find('.cpw-dropdown-icon').html();

            // Update ARIA.
            $('.cpw-dropdown-option').attr('aria-selected', 'false');
            $opt.attr('aria-selected', 'true');

            // Update trigger display.
            var $trigger = $('.cpw-dropdown-trigger');
            $trigger.find('.cpw-dropdown-trigger-text').html(
                '<span class="cpw-dropdown-icon">' + svg + '</span>' +
                '<span class="cpw-dropdown-name">' + $('<span>').text(name).html() + '</span>'
            );

            // Set hidden input and fire change.
            $('#cpw_network').val(value).trigger('change');

            // Close dropdown and return focus.
            this.toggleDropdown(false);
            $trigger.focus();
        },

        handleTriggerKeydown: function (e) {
            var key = e.key;
            if (key === 'ArrowDown' || key === 'ArrowUp' || key === 'Enter' || key === ' ') {
                e.preventDefault();
                this.toggleDropdown(true);
            } else if (key === 'Escape') {
                this.toggleDropdown(false);
            }
        },

        handleOptionKeydown: function (e) {
            var key = e.key;
            var $current = $(e.target);
            var $allOptions = $('.cpw-dropdown-menu .cpw-dropdown-option:visible');
            var idx = $allOptions.index($current);

            switch (key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (idx < $allOptions.length - 1) {
                        $allOptions.eq(idx + 1).focus();
                    }
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (idx > 0) {
                        $allOptions.eq(idx - 1).focus();
                    }
                    break;
                case 'Home':
                    e.preventDefault();
                    $allOptions.first().focus();
                    break;
                case 'End':
                    e.preventDefault();
                    $allOptions.last().focus();
                    break;
                case 'Enter':
                case ' ':
                    e.preventDefault();
                    this.selectOption($current);
                    break;
                case 'Escape':
                    e.preventDefault();
                    this.toggleDropdown(false);
                    $('.cpw-dropdown-trigger').focus();
                    break;
            }
        },

        // ── Network change handler ───────────────────────────────────

        onNetworkChange: function () {
            var networkId = $('#cpw_network').val();

            if (!networkId) {
                $('#cpw-payment-details').hide();
                this.updatePlaceOrderButton(false);
                return;
            }

            this.currentNetwork = networkId;
            this.fetchPrice(networkId);
        },

        fetchPrice: function (networkId) {
            var self = this;

            // Show loading state.
            $('#cpw-payment-details').show();
            $('#cpw-loading').show();
            $('#cpw-payment-info').hide();

            $.ajax({
                url: cpw_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'cpw_get_crypto_price',
                    nonce: cpw_data.nonce_price,
                    network_id: networkId,
                    order_id: cpw_data.order_id,
                    order_key: cpw_data.order_key,
                },
                success: function (response) {
                    if (response.success) {
                        self.displayPaymentInfo(response.data);
                    } else {
                        self.showError(response.data.message || 'Could not fetch price.');
                    }
                },
                error: function () {
                    self.showError('Network error. Please try again.');
                },
            });
        },

        displayPaymentInfo: function (data) {
            // Populate the payment info.
            $('#cpw-crypto-amount').text(data.crypto_amount);
            $('#cpw-crypto-symbol').text(data.symbol);
            $('#cpw-fiat-equiv').text('≈ ' + this.formatCurrency(data.fiat_amount, data.currency));
            $('#cpw-wallet-address').text(data.wallet_address);

            // Set hidden field for form submission.
            $('#cpw_crypto_amount_hidden').val(data.crypto_amount);

            // Generate QR code.
            this.generateQR(data.wallet_address, data);

            // Start timer.
            this.startTimer();

            // Show the info.
            $('#cpw-loading').hide();
            $('#cpw-payment-info').show();

            // Change the "Place Order" button text.
            this.updatePlaceOrderButton(true);
        },

        generateQR: function (address, data) {
            var qrContainer = document.getElementById('cpw-qr-code');
            qrContainer.innerHTML = '';

            // Build the QR code value.
            // Only Bitcoin BIP-21 is widely supported by wallets.
            // For everything else, use the plain address — it's universally scannable.
            var qrValue = address;

            if (data.symbol === 'BTC') {
                // BIP-21 is well supported by virtually all BTC wallets.
                qrValue = 'bitcoin:' + address + '?amount=' + data.crypto_amount;
            }

            if (typeof QRCode !== 'undefined') {
                new QRCode(qrContainer, {
                    text: qrValue,
                    width: 180,
                    height: 180,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M,
                });
            }
        },

        startTimer: function () {
            var self = this;

            if (this.timer) {
                clearInterval(this.timer);
            }

            this.timerSeconds = (parseInt(cpw_data.payment_window, 10) || 15) * 60;
            this.updateTimerDisplay();

            this.timer = setInterval(function () {
                self.timerSeconds--;

                if (self.timerSeconds <= 0) {
                    clearInterval(self.timer);
                    self.timerExpired();
                    return;
                }

                self.updateTimerDisplay();
            }, 1000);
        },

        updateTimerDisplay: function () {
            var minutes = Math.floor(this.timerSeconds / 60);
            var seconds = this.timerSeconds % 60;
            var display = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
            $('#cpw-timer').text(display);

            // Warn when under 2 minutes.
            if (this.timerSeconds < 120) {
                $('.cpw-timer-box').css('background', '#fef2f2').css('border-color', '#fecaca');
                $('#cpw-timer').css('color', '#dc2626');
            }
        },

        timerExpired: function () {
            var $timerBox = $('.cpw-timer-box');
            $timerBox.addClass('expired');
            $('#cpw-timer').text('EXPIRED');
            $timerBox.find('span').first().text('⚠️ Price expired. ');
            $timerBox.append(
                '<br><button type="button" id="cpw-refresh-price" ' +
                'style="margin-top:8px; padding:6px 16px; background:#7c3aed; color:#fff; ' +
                'border:none; border-radius:6px; cursor:pointer; font-size:13px;">' +
                'Refresh Price</button>'
            );

            $(document.body).on('click', '#cpw-refresh-price', function () {
                // Re-fetch the price.
                $timerBox.removeClass('expired').css('background', '').css('border-color', '');
                $('#cpw-refresh-price').remove();
                cpw.fetchPrice(cpw.currentNetwork);
            });
        },

        copyAmount: function (e) {
            e.preventDefault();
            var amount = $('#cpw-crypto-amount').text();
            this.copyToClipboard(amount, '#cpw-copy-amount');
        },

        copyAddress: function (e) {
            e.preventDefault();
            var address = $('#cpw-wallet-address').text();
            this.copyToClipboard(address, '#cpw-copy-address');
        },

        copyToClipboard: function (text, buttonSelector) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    $(buttonSelector).addClass('copied').text('✅');
                    setTimeout(function () {
                        $(buttonSelector).removeClass('copied').text('📋');
                    }, 2000);
                });
            } else {
                // Fallback for older browsers.
                var $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(text).select();
                document.execCommand('copy');
                $temp.remove();
                $(buttonSelector).addClass('copied').text('✅');
                setTimeout(function () {
                    $(buttonSelector).removeClass('copied').text('📋');
                }, 2000);
            }
        },

        formatCurrency: function (amount, currency) {
            try {
                return new Intl.NumberFormat(undefined, {
                    style: 'currency',
                    currency: currency,
                }).format(amount);
            } catch (e) {
                return currency + ' ' + parseFloat(amount).toFixed(2);
            }
        },

        showError: function (message) {
            $('#cpw-loading').hide();
            var $container = $('<div>').css({ textAlign: 'center', padding: '16px', color: '#dc2626' });
            $container.append($('<p>').text('⚠️ ' + message));
            $container.append(
                $('<button>').attr('type', 'button')
                    .css({ padding: '6px 16px', background: '#7c3aed', color: '#fff', border: 'none', borderRadius: '6px', cursor: 'pointer' })
                    .text('Try Again')
                    .on('click', function () { $('#cpw_network').trigger('change'); })
            );
            $('#cpw-payment-info').empty().append($container).show();
        },

        /**
         * Update the "Place Order" button text to guide the customer.
         */
        updatePlaceOrderButton: function (isCrypto) {
            var $btn = $('#place_order');
            if (!$btn.length) return;

            if (isCrypto) {
                // Store original text if not already stored.
                if (!$btn.data('cpw-original-text')) {
                    $btn.data('cpw-original-text', $btn.val() || $btn.text());
                }
                $btn.val('✅ I\'ve Sent the Payment — Complete Order');
            } else {
                // Restore original text.
                var original = $btn.data('cpw-original-text');
                if (original) {
                    $btn.val(original);
                }
            }
        },
    };

    $(document).ready(function () {
        cpw.init();
    });
})(jQuery);
