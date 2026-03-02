/**
 * Crypto Payments for WooCommerce - Block Checkout Integration
 *
 * This script registers the crypto payment method with WooCommerce's
 * block-based checkout using the Blocks Registry API.
 */
(function () {
    'use strict';

    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { createElement, useState, useEffect, useRef, useCallback } = window.wp.element;
    const { decodeEntities } = window.wp.htmlEntities;

    // Get server-side settings passed via wp_localize_script and get_payment_method_data.
    const settings = window.wc.wcSettings.getSetting('crypto_payments_data', {});
    const blockData = window.cpw_block_data || {};

    const title = decodeEntities(settings.title || 'Pay with Crypto');
    const description = decodeEntities(settings.description || '');
    const networks = settings.networks || blockData.networks || [];
    const ajaxUrl = settings.ajax_url || blockData.ajax_url || '';
    const nonce = settings.nonce_price || blockData.nonce_price || '';
    const paymentWindowMinutes = parseInt(settings.payment_window || blockData.payment_window || '15', 10) || 15;

    /**
     * Label component shown in the payment method list.
     */
    const Label = (props) => {
        const { PaymentMethodLabel } = props.components;
        return createElement(PaymentMethodLabel, { text: title });
    };

    /**
     * Main payment content component rendered when the user selects crypto payments.
     */
    const Content = (props) => {
        const { eventRegistration, emitResponse } = props;
        const { onPaymentSetup } = eventRegistration;

        const [selectedNetwork, setSelectedNetwork] = useState('');
        const [loading, setLoading] = useState(false);
        const [error, setError] = useState('');
        const [paymentData, setPaymentData] = useState(null);
        const [timerSeconds, setTimerSeconds] = useState(0);
        const [txHash, setTxHash] = useState('');
        const [copiedField, setCopiedField] = useState('');
        const [dropdownOpen, setDropdownOpen] = useState(false);
        const timerRef = useRef(null);
        const qrRef = useRef(null);
        const dropdownRef = useRef(null);

        // Register payment setup handler - this fires when "Place Order" is clicked.
        useEffect(() => {
            const unsubscribe = onPaymentSetup(() => {
                if (!selectedNetwork) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Please select a cryptocurrency to pay with.',
                    };
                }

                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            cpw_network: selectedNetwork,
                            cpw_tx_hash: txHash,
                            cpw_crypto_amount: paymentData ? paymentData.crypto_amount : '',
                        },
                    },
                };
            });

            return unsubscribe;
        }, [onPaymentSetup, emitResponse, selectedNetwork, txHash, paymentData]);

        // Fetch price when network changes.
        useEffect(() => {
            if (!selectedNetwork) {
                setPaymentData(null);
                return;
            }
            fetchPrice(selectedNetwork);
        }, [selectedNetwork]);

        // Timer countdown.
        useEffect(() => {
            if (timerSeconds <= 0) {
                if (timerRef.current) clearInterval(timerRef.current);
                return;
            }

            timerRef.current = setInterval(() => {
                setTimerSeconds((prev) => {
                    if (prev <= 1) {
                        clearInterval(timerRef.current);
                        return 0;
                    }
                    return prev - 1;
                });
            }, 1000);

            return () => clearInterval(timerRef.current);
        }, [timerSeconds > 0]);

        // Generate QR code when payment data changes.
        useEffect(() => {
            if (!paymentData || !qrRef.current) return;
            generateQR();
        }, [paymentData]);

        // Close dropdown on outside click.
        useEffect(() => {
            const handleClickOutside = (e) => {
                if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
                    setDropdownOpen(false);
                }
            };
            document.addEventListener('mousedown', handleClickOutside);
            return () => document.removeEventListener('mousedown', handleClickOutside);
        }, []);

        const fetchPrice = (networkId) => {
            const fiatAmount = getOrderTotal();
            const currency = getStoreCurrency();

            if (!fiatAmount || fiatAmount <= 0) {
                setError('Could not determine order total.');
                return;
            }

            setLoading(true);
            setError('');
            setPaymentData(null);

            const formData = new FormData();
            formData.append('action', 'cpw_get_crypto_price');
            formData.append('nonce', nonce);
            formData.append('network_id', networkId);
            formData.append('fiat_amount', fiatAmount);
            formData.append('currency', currency);

            fetch(ajaxUrl, { method: 'POST', body: formData })
                .then((res) => res.json())
                .then((response) => {
                    setLoading(false);
                    if (response.success) {
                        setPaymentData(response.data);
                        setTimerSeconds(paymentWindowMinutes * 60);
                    } else {
                        setError(response.data?.message || 'Could not fetch price.');
                    }
                })
                .catch(() => {
                    setLoading(false);
                    setError('Network error. Please try again.');
                });
        };

        const generateQR = () => {
            if (!qrRef.current || !paymentData) return;

            qrRef.current.innerHTML = '';

            // Only Bitcoin BIP-21 is widely supported by wallets.
            // For everything else, use the plain address — universally scannable.
            let qrValue = paymentData.wallet_address;

            if (paymentData.symbol === 'BTC') {
                qrValue = 'bitcoin:' + paymentData.wallet_address + '?amount=' + paymentData.crypto_amount;
            }

            if (typeof QRCode !== 'undefined') {
                new QRCode(qrRef.current, {
                    text: qrValue,
                    width: 180,
                    height: 180,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M,
                });
            }
        };

        const copyToClipboard = (text, field) => {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => {
                    setCopiedField(field);
                    setTimeout(() => setCopiedField(''), 2000);
                });
            } else {
                const ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                setCopiedField(field);
                setTimeout(() => setCopiedField(''), 2000);
            }
        };

        const getOrderTotal = () => {
            // Try multiple selectors for the block checkout total.
            const selectors = [
                '.wc-block-components-totals-footer-item .wc-block-formatted-money-amount',
                '.wc-block-components-totals-footer-item .wc-block-components-formatted-money-amount',
                '.wp-block-woocommerce-checkout-totals-block .wc-block-formatted-money-amount:last-child',
                '.wc-block-components-order-summary .wc-block-formatted-money-amount',
                '.order-total .woocommerce-Price-amount bdi',
                '.order-total .amount',
            ];

            for (const selector of selectors) {
                const elements = document.querySelectorAll(selector);
                if (elements.length > 0) {
                    // Get the last match (usually the grand total).
                    const el = elements[elements.length - 1];
                    const text = el.textContent || el.innerText;
                    return parseAmount(text);
                }
            }

            return 0;
        };

        const parseAmount = (text) => {
            let cleaned = text.replace(/[^\d.,]/g, '');

            if (cleaned.indexOf(',') > -1 && cleaned.indexOf('.') > -1) {
                if (cleaned.lastIndexOf(',') > cleaned.lastIndexOf('.')) {
                    cleaned = cleaned.replace(/\./g, '').replace(',', '.');
                } else {
                    cleaned = cleaned.replace(/,/g, '');
                }
            } else if (cleaned.indexOf(',') > -1) {
                const parts = cleaned.split(',');
                if (parts[parts.length - 1].length === 3 && parts.length === 2) {
                    cleaned = cleaned.replace(',', '');
                } else {
                    cleaned = cleaned.replace(',', '.');
                }
            }

            return parseFloat(cleaned) || 0;
        };

        const getStoreCurrency = () => {
            if (window.wcSettings && window.wcSettings.currency && window.wcSettings.currency.code) {
                return window.wcSettings.currency.code;
            }
            // Try wc store data.
            try {
                const storeData = window.wc.wcSettings.getSetting('currency', {});
                if (storeData.code) return storeData.code;
            } catch (e) {}
            return 'USD';
        };

        const formatTimer = (seconds) => {
            if (seconds <= 0) return 'EXPIRED';
            const m = Math.floor(seconds / 60);
            const s = seconds % 60;
            return m + ':' + (s < 10 ? '0' : '') + s;
        };

        const formatCurrency = (amount, currency) => {
            try {
                return new Intl.NumberFormat(undefined, {
                    style: 'currency',
                    currency: currency,
                }).format(amount);
            } catch (e) {
                return currency + ' ' + parseFloat(amount).toFixed(2);
            }
        };

        // Update the "Place Order" button text when payment data is shown/hidden.
        useEffect(() => {
            const btn = document.querySelector(
                '.wc-block-components-checkout-place-order-button, .wc-block-checkout__actions button'
            );
            if (!btn) return;

            if (!btn.dataset.cpwOriginal) {
                btn.dataset.cpwOriginal = btn.textContent;
            }

            if (paymentData) {
                btn.textContent = '✅ I\'ve Sent the Payment — Complete Order';
            } else {
                btn.textContent = btn.dataset.cpwOriginal;
            }

            return () => {
                if (btn && btn.dataset.cpwOriginal) {
                    btn.textContent = btn.dataset.cpwOriginal;
                }
            };
        }, [paymentData]);

        // ── Dropdown helpers ─────────────────────────────────────────

        // Find the selected network object.
        const getSelectedNet = () => {
            for (const group of networks) {
                for (const net of group.items) {
                    if (net.id === selectedNetwork) return net;
                }
            }
            return null;
        };

        // Flatten all options for keyboard navigation.
        const getAllOptions = () => {
            const all = [];
            for (const group of networks) {
                for (const net of group.items) {
                    all.push(net);
                }
            }
            return all;
        };

        const handleTriggerKeydown = (e) => {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                setDropdownOpen(true);
            } else if (e.key === 'Escape') {
                setDropdownOpen(false);
            }
        };

        const handleOptionKeydown = (e, netId) => {
            const allOpts = getAllOptions();
            const idx = allOpts.findIndex((n) => n.id === netId);

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (idx < allOpts.length - 1) {
                        const nextEl = dropdownRef.current?.querySelector(
                            '[data-value="' + allOpts[idx + 1].id + '"]'
                        );
                        if (nextEl) nextEl.focus();
                    }
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (idx > 0) {
                        const prevEl = dropdownRef.current?.querySelector(
                            '[data-value="' + allOpts[idx - 1].id + '"]'
                        );
                        if (prevEl) prevEl.focus();
                    }
                    break;
                case 'Home':
                    e.preventDefault();
                    if (allOpts.length) {
                        const firstEl = dropdownRef.current?.querySelector(
                            '[data-value="' + allOpts[0].id + '"]'
                        );
                        if (firstEl) firstEl.focus();
                    }
                    break;
                case 'End':
                    e.preventDefault();
                    if (allOpts.length) {
                        const lastEl = dropdownRef.current?.querySelector(
                            '[data-value="' + allOpts[allOpts.length - 1].id + '"]'
                        );
                        if (lastEl) lastEl.focus();
                    }
                    break;
                case 'Enter':
                case ' ':
                    e.preventDefault();
                    setSelectedNetwork(netId);
                    setDropdownOpen(false);
                    break;
                case 'Escape':
                    e.preventDefault();
                    setDropdownOpen(false);
                    break;
            }
        };

        // ── Render ──────────────────────────────────────────────────

        const els = createElement;
        const selectedNet = getSelectedNet();

        // Trigger button content.
        const triggerContent = selectedNet
            ? els(
                  'span',
                  { className: 'cpw-dropdown-trigger-text' },
                  els('span', {
                      className: 'cpw-dropdown-icon',
                      dangerouslySetInnerHTML: { __html: selectedNet.svg || '' },
                  }),
                  els('span', { className: 'cpw-dropdown-name' }, selectedNet.name)
              )
            : els(
                  'span',
                  { className: 'cpw-dropdown-trigger-text' },
                  '— Select cryptocurrency —'
              );

        // Dropdown arrow SVG.
        const arrowSvg = els(
            'svg',
            {
                className: 'cpw-dropdown-arrow',
                viewBox: '0 0 12 12',
                width: 12,
                height: 12,
                'aria-hidden': 'true',
            },
            els('path', { fill: '#6b7280', d: 'M6 8L1 3h10z' })
        );

        // Network selector (custom dropdown).
        const selectorEl = els(
            'div',
            { className: 'cpw-block-selector' },
            els(
                'label',
                { id: 'cpw-block-network-label', className: 'cpw-block-label' },
                'Choose a cryptocurrency:'
            ),
            els(
                'div',
                {
                    className: 'cpw-dropdown',
                    ref: dropdownRef,
                    role: 'combobox',
                    'aria-expanded': dropdownOpen ? 'true' : 'false',
                    'aria-haspopup': 'listbox',
                    'aria-labelledby': 'cpw-block-network-label',
                },
                els(
                    'button',
                    {
                        type: 'button',
                        className: 'cpw-dropdown-trigger',
                        'aria-expanded': dropdownOpen ? 'true' : 'false',
                        onClick: (e) => {
                            e.preventDefault();
                            setDropdownOpen(!dropdownOpen);
                        },
                        onKeyDown: handleTriggerKeydown,
                    },
                    triggerContent,
                    arrowSvg
                ),
                dropdownOpen
                    ? els(
                          'div',
                          {
                              className: 'cpw-dropdown-menu cpw-dropdown-menu--open',
                              role: 'listbox',
                              'aria-labelledby': 'cpw-block-network-label',
                          },
                          ...networks.map((group) =>
                              els(
                                  'div',
                                  {
                                      className: 'cpw-dropdown-group',
                                      role: 'group',
                                      'aria-label': group.group,
                                      key: group.group,
                                  },
                                  els(
                                      'div',
                                      { className: 'cpw-dropdown-group-label' },
                                      group.group
                                  ),
                                  ...group.items.map((net) =>
                                      els(
                                          'div',
                                          {
                                              className:
                                                  'cpw-dropdown-option' +
                                                  (net.id === selectedNetwork
                                                      ? ' cpw-dropdown-option--selected'
                                                      : ''),
                                              role: 'option',
                                              'aria-selected':
                                                  net.id === selectedNetwork ? 'true' : 'false',
                                              'data-value': net.id,
                                              tabIndex: 0,
                                              key: net.id,
                                              onClick: () => {
                                                  setSelectedNetwork(net.id);
                                                  setDropdownOpen(false);
                                              },
                                              onKeyDown: (e) => handleOptionKeydown(e, net.id),
                                          },
                                          els('span', {
                                              className: 'cpw-dropdown-icon',
                                              dangerouslySetInnerHTML: {
                                                  __html: net.svg || '',
                                              },
                                          }),
                                          els(
                                              'span',
                                              { className: 'cpw-dropdown-name' },
                                              net.name
                                          )
                                      )
                                  )
                              )
                          )
                      )
                    : null
            )
        );

        // Loading state.
        if (loading) {
            return els(
                'div',
                { className: 'cpw-block-wrapper' },
                selectorEl,
                els(
                    'div',
                    { className: 'cpw-block-loading' },
                    els('span', { className: 'cpw-block-spinner' }),
                    ' Fetching live price…'
                )
            );
        }

        // Error state.
        if (error) {
            return els(
                'div',
                { className: 'cpw-block-wrapper' },
                selectorEl,
                els(
                    'div',
                    { className: 'cpw-block-error' },
                    '⚠️ ' + error,
                    els(
                        'button',
                        {
                            type: 'button',
                            className: 'cpw-block-retry-btn',
                            onClick: () => fetchPrice(selectedNetwork),
                        },
                        'Try Again'
                    )
                )
            );
        }

        // Payment info.
        let paymentInfoEl = null;
        if (paymentData) {
            const fiatAmount = getOrderTotal();
            const currency = getStoreCurrency();

            paymentInfoEl = els(
                'div',
                { className: 'cpw-block-payment-info' },

                // Step 1: Amount.
                els(
                    'div',
                    { className: 'cpw-block-step-header' },
                    els('span', { className: 'cpw-block-step-num' }, '1'),
                    'Send this amount'
                ),
                els(
                    'div',
                    { className: 'cpw-block-amount-box' },
                    els(
                        'div',
                        { className: 'cpw-block-amount-value' },
                        els('span', { className: 'cpw-block-amount-num' }, paymentData.crypto_amount),
                        ' ',
                        els('span', { className: 'cpw-block-amount-sym' }, paymentData.symbol),
                        ' ',
                        els(
                            'button',
                            {
                                type: 'button',
                                className: 'cpw-block-copy-btn',
                                onClick: () => copyToClipboard(paymentData.crypto_amount, 'amount'),
                                title: 'Copy amount',
                            },
                            copiedField === 'amount' ? '✅' : '📋'
                        )
                    ),
                    els(
                        'div',
                        { className: 'cpw-block-fiat-equiv' },
                        '≈ ' + formatCurrency(fiatAmount, currency)
                    )
                ),

                // Step 2: Address.
                els(
                    'div',
                    { className: 'cpw-block-step-header' },
                    els('span', { className: 'cpw-block-step-num' }, '2'),
                    'To this address'
                ),
                els(
                    'div',
                    { className: 'cpw-block-address-box' },
                    els('div', { className: 'cpw-block-qr', ref: qrRef }),
                    els(
                        'div',
                        { className: 'cpw-block-address-row' },
                        els('code', { className: 'cpw-block-address' }, paymentData.wallet_address),
                        els(
                            'button',
                            {
                                type: 'button',
                                className: 'cpw-block-copy-btn',
                                onClick: () => copyToClipboard(paymentData.wallet_address, 'address'),
                                title: 'Copy address',
                            },
                            copiedField === 'address' ? '✅' : '📋'
                        )
                    )
                ),

                // Timer.
                els(
                    'div',
                    {
                        className:
                            'cpw-block-timer' + (timerSeconds <= 0 ? ' expired' : timerSeconds < 120 ? ' warning' : ''),
                    },
                    timerSeconds > 0
                        ? els(
                              'span',
                              null,
                              '⏱ Price valid for: ',
                              els('strong', null, formatTimer(timerSeconds))
                          )
                        : els(
                              'span',
                              null,
                              '⚠️ Price expired. ',
                              els(
                                  'button',
                                  {
                                      type: 'button',
                                      className: 'cpw-block-refresh-btn',
                                      onClick: () => fetchPrice(selectedNetwork),
                                  },
                                  'Refresh Price'
                              )
                          )
                ),

                // Step 3: Confirm.
                els(
                    'div',
                    { className: 'cpw-block-step-header' },
                    els('span', { className: 'cpw-block-step-num' }, '3'),
                    'Paste your transaction hash (optional) and click the button below'
                ),
                els(
                    'div',
                    { className: 'cpw-block-tx-hash' },
                    els('input', {
                        type: 'text',
                        id: 'cpw-block-tx-hash',
                        className: 'cpw-block-tx-input',
                        placeholder: 'Paste your transaction hash for faster verification',
                        value: txHash,
                        onChange: (e) => setTxHash(e.target.value),
                    }),
                    els(
                        'small',
                        { className: 'cpw-block-tx-hint' },
                        'Providing the TX hash helps us confirm your payment faster.'
                    )
                )
            );
        }

        return els(
            'div',
            { className: 'cpw-block-wrapper' },
            selectorEl,
            paymentInfoEl
        );
    };

    // ── Register the payment method ─────────────────────────────────

    registerPaymentMethod({
        name: 'crypto_payments',
        label: createElement(Label, null),
        content: createElement(Content, null),
        edit: createElement(Content, null),
        canMakePayment: () => true,
        ariaLabel: title,
        supports: {
            features: settings.supports || ['products'],
        },
    });
})();
