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
                // Re-bind if needed; the select should persist.
            });
        },

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

            // Get the order total from the checkout page.
            var fiatAmount = this.getOrderTotal();
            var currency = this.getStoreCurrency();

            if (!fiatAmount || fiatAmount <= 0) {
                // Fallback: try to read from a hidden field or the page.
                console.warn('CPW: Could not determine order total.');
                return;
            }

            // Show loading state.
            $('#cpw-payment-details').show();
            $('#cpw-loading').show();
            $('#cpw-payment-info').hide();

            $.ajax({
                url: cpw_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'cpw_get_crypto_price',
                    nonce: cpw_data.nonce,
                    network_id: networkId,
                    fiat_amount: fiatAmount,
                    currency: currency,
                },
                success: function (response) {
                    if (response.success) {
                        self.displayPaymentInfo(response.data, fiatAmount, currency);
                    } else {
                        self.showError(response.data.message || 'Could not fetch price.');
                    }
                },
                error: function () {
                    self.showError('Network error. Please try again.');
                },
            });
        },

        displayPaymentInfo: function (data, fiatAmount, currency) {
            // Populate the payment info.
            $('#cpw-crypto-amount').text(data.crypto_amount);
            $('#cpw-crypto-symbol').text(data.symbol);
            $('#cpw-fiat-equiv').text('≈ ' + this.formatCurrency(fiatAmount, currency));
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

            // Default 15 minutes.
            this.timerSeconds = 15 * 60;
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

        getOrderTotal: function () {
            // Try to get the total from WooCommerce's checkout.
            // Method 1: From the order review table.
            var totalEl = document.querySelector(
                '.order-total .woocommerce-Price-amount bdi, .order-total .amount'
            );
            if (totalEl) {
                var text = totalEl.textContent || totalEl.innerText;
                // Remove currency symbols and thousands separators, keep decimal.
                var cleaned = text.replace(/[^\d.,]/g, '');
                // Handle different locale formats.
                // If there's both a comma and period, determine which is the decimal.
                if (cleaned.indexOf(',') > -1 && cleaned.indexOf('.') > -1) {
                    if (cleaned.lastIndexOf(',') > cleaned.lastIndexOf('.')) {
                        // Comma is the decimal separator (e.g., 1.234,56).
                        cleaned = cleaned.replace(/\./g, '').replace(',', '.');
                    } else {
                        // Period is the decimal separator (e.g., 1,234.56).
                        cleaned = cleaned.replace(/,/g, '');
                    }
                } else if (cleaned.indexOf(',') > -1) {
                    // Could be decimal or thousands. If 3 digits after comma, it's thousands.
                    var parts = cleaned.split(',');
                    if (parts[parts.length - 1].length === 3 && parts.length === 2) {
                        cleaned = cleaned.replace(',', '');
                    } else {
                        cleaned = cleaned.replace(',', '.');
                    }
                }
                return parseFloat(cleaned) || 0;
            }

            return 0;
        },

        getStoreCurrency: function () {
            // Try to detect from the page. Fallback to USD.
            var currencyEl = document.querySelector(
                '.woocommerce-Price-currencySymbol'
            );
            if (currencyEl) {
                var sym = currencyEl.textContent.trim();
                var symbolMap = {
                    '$': 'USD',
                    '€': 'EUR',
                    '£': 'GBP',
                    '¥': 'JPY',
                    'A$': 'AUD',
                    'C$': 'CAD',
                    'CHF': 'CHF',
                    'kr': 'SEK',
                    'R$': 'BRL',
                };
                if (symbolMap[sym]) {
                    return symbolMap[sym];
                }
            }

            // Fallback: check for wc_checkout_params if available.
            if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.currency) {
                return wc_checkout_params.currency;
            }

            return 'USD';
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
            $('#cpw-payment-info')
                .html(
                    '<div style="text-align:center; padding:16px; color:#dc2626;">' +
                    '<p>⚠️ ' + message + '</p>' +
                    '<button type="button" onclick="jQuery(\'#cpw_network\').trigger(\'change\')" ' +
                    'style="padding:6px 16px; background:#7c3aed; color:#fff; border:none; border-radius:6px; cursor:pointer;">' +
                    'Try Again</button></div>'
                )
                .show();
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
