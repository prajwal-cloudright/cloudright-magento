define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    return function (config, element) {

        var STORAGE_KEY = 'cloudright_customer',
            initialized = false,
            checkoutInterceptorBound = false,
            $root = $(element),
            cartApiUrl = config.cartApiUrl,
            orderApiUrl = config.orderApiUrl,
            cartPageUrl = config.cartPageUrl,
            successPageUrl = config.successPageUrl,
            latestCartPayload = null,
            currentCustomer = null;


        /* =========================================================
         * HELPERS
         * ========================================================= */

        function getStoredCustomer() {

            try {

                var value =
                    window.localStorage.getItem(
                        STORAGE_KEY
                    );

                return value
                    ? JSON.parse(value)
                    : null;

            } catch (error) {

                return null;
            }
        }


        function saveCustomer(customer) {

            try {

                window.localStorage.setItem(
                    STORAGE_KEY,
                    JSON.stringify(customer)
                );

            } catch (error) {
                // Ignore localStorage failures.
            }
        }


        function clearCustomer() {

            try {

                window.localStorage.removeItem(
                    STORAGE_KEY
                );

            } catch (error) {
                // Ignore localStorage failures.
            }
        }


        function escapeHtml(value) {

            return $('<div>')
                .text(
                    value == null
                        ? ''
                        : value
                )
                .html();
        }


        function formatMoney(value) {

            var number =
                Number(value || 0);

            return '₹' +
                number.toLocaleString(
                    'en-IN',
                    {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }
                );
        }


        function getFormattedTotal(total) {

            if (!total) {
                return '—';
            }

            if (total.formatted) {
                return total.formatted;
            }

            if (
                typeof total.value !== 'undefined'
            ) {
                return formatMoney(
                    total.value
                );
            }

            return formatMoney(total);
        }


        /* =========================================================
         * ERROR HANDLING
         * ========================================================= */

        function showError(message) {

            var $activePanel =
                $root.find(
                    '[data-step-panel].is-active'
                );

            var activeStep =
                $activePanel.attr(
                    'data-step-panel'
                );


            if (
                activeStep === 'payment'
            ) {

                var $paymentError =
                    $root.find(
                        '[data-cloudright-payment-error]'
                    );

                if ($paymentError.length) {

                    $paymentError
                        .text(message)
                        .removeAttr('hidden')
                        .removeClass('is-hidden')
                        .show();

                    return;
                }
            }


            var $emailError =
                $root.find(
                    '[data-cloudright-email-error]'
                );

            if ($emailError.length) {

                $emailError
                    .text(message)
                    .removeAttr('hidden')
                    .removeClass('is-hidden')
                    .show();
            }
        }


        function hideError() {

            $root
                .find(
                    '[data-cloudright-email-error]'
                )
                .text('')
                .attr(
                    'hidden',
                    'hidden'
                )
                .addClass('is-hidden')
                .hide();


            $root
                .find(
                    '[data-cloudright-payment-error]'
                )
                .text('')
                .attr(
                    'hidden',
                    'hidden'
                )
                .addClass('is-hidden')
                .hide();
        }


        /* =========================================================
         * BUTTON LOADING
         * ========================================================= */

        function setButtonLoading(
            $button,
            loadingText
        ) {

            if (!$button.length) {
                return;
            }

            $button
                .prop('disabled', true)
                .addClass('is-loading');

            var $label =
                $button.find(
                    '.cloudright-btn-label'
                );

            var $arrow =
                $button.find(
                    '.cloudright-button-arrow'
                );

            var $spinner =
                $button.find(
                    '.cloudright-spinner'
                );


            if ($label.length) {

                $label.text(
                    loadingText
                );

            } else {

                $button.text(
                    loadingText
                );
            }


            $arrow.hide();

            $spinner
                .removeAttr('hidden')
                .show();
        }


        function resetButton(
            $button,
            buttonText
        ) {

            if (!$button.length) {
                return;
            }

            $button
                .prop('disabled', false)
                .removeClass('is-loading');


            var $label =
                $button.find(
                    '.cloudright-btn-label'
                );

            var $arrow =
                $button.find(
                    '.cloudright-button-arrow'
                );

            var $spinner =
                $button.find(
                    '.cloudright-spinner'
                );


            if ($label.length) {

                $label.text(
                    buttonText
                );

            } else {

                $button.text(
                    buttonText
                );
            }


            $arrow.show();

            $spinner
                .attr(
                    'hidden',
                    'hidden'
                )
                .hide();
        }


        /* =========================================================
         * MODAL
         * ========================================================= */

        function openModal() {

            $root
                .addClass('is-open')
                .attr(
                    'aria-hidden',
                    'false'
                )
                .show();

            $('body').css(
                'overflow',
                'hidden'
            );

            loadCart();
        }


        function closeModal() {

            $root
                .removeClass('is-open')
                .attr(
                    'aria-hidden',
                    'true'
                )
                .hide();

            $('body').css(
                'overflow',
                ''
            );

            hideError();
        }


        function resetModal() {

            latestCartPayload = null;


            /* Reset panels */

            $root
                .find('[data-step-panel]')
                .removeClass('is-active')
                .hide();


            $root
                .find(
                    '[data-step-panel="account"]'
                )
                .addClass('is-active')
                .show();


            /* Reset progress */

            $root
                .find('[data-step-indicator]')
                .removeClass(
                    'is-active completed'
                );


            $root
                .find(
                    '[data-step-indicator="account"]'
                )
                .addClass('is-active');


            /* Reset email */

            $root
                .find(
                    '[data-cloudright-email-input]'
                )
                .val('');


            /* Reset customer */

            $root
                .find(
                    '[data-cloudright-customer-name]'
                )
                .text('—');


            $root
                .find(
                    '[data-cloudright-customer-email]'
                )
                .text('—');


            /* Reset account button */

            resetButton(
                $root.find(
                    '[data-cloudright-continue]'
                ),
                $t('Continue')
            );


            /* Reset payment button */

            resetButton(
                $root.find(
                    '[data-cloudright-pay]'
                ),
                $t('Pay Securely')
            );


            /* Reset payment items */

            $root
                .find(
                    '[data-cloudright-payment-items]'
                )
                .empty();


            $root
                .find(
                    '[data-cloudright-payment-total]'
                )
                .text('—');


            /* Reset order preview */

            $root
                .find(
                    '[data-cloudright-summary-items]'
                )
                .html(
                    '<p class="cloudright-summary-loading" ' +
                    'data-cloudright-cart-loading>' +
                    escapeHtml(
                        $t('Loading your cart...')
                    ) +
                    '</p>'
                );


            $root
                .find(
                    '[data-cloudright-summary-totals]'
                )
                .hide();


            $root
                .find(
                    '[data-cloudright-grand-total]'
                )
                .text('—');


            /* Reset confirmation */

            $root
                .find(
                    '[data-cloudright-order-number]'
                )
                .text('—');


            $root
                .find(
                    '[data-cloudright-payment-status]'
                )
                .text(
                    $t('Paid')
                );


            hideError();
        }


        /* =========================================================
         * STEP MANAGEMENT
         * ========================================================= */

        function showStep(step) {

            var steps = [
                'account',
                'payment',
                'confirmation'
            ];

            var currentIndex =
                steps.indexOf(step);


            if (currentIndex === -1) {
                return;
            }


            /* Hide all panels */

            $root
                .find('[data-step-panel]')
                .removeClass('is-active')
                .hide();


            /* Show selected panel */

            $root
                .find(
                    '[data-step-panel="' +
                    step +
                    '"]'
                )
                .addClass('is-active')
                .show();


            /* Update progress */

            $root
                .find('[data-step-indicator]')
                .removeClass(
                    'is-active completed'
                );


            $.each(
                steps,
                function (index, stepName) {

                    var $indicator =
                        $root.find(
                            '[data-step-indicator="' +
                            stepName +
                            '"]'
                        );


                    if (index < currentIndex) {

                        $indicator.addClass(
                            'completed'
                        );

                    } else if (
                        index === currentIndex
                    ) {

                        $indicator.addClass(
                            'is-active'
                        );
                    }
                }
            );


            hideError();
        }


        /* =========================================================
         * CART
         * ========================================================= */

        function loadCart() {

            hideError();


            var $loading =
                $root.find(
                    '[data-cloudright-cart-loading]'
                );


            $loading
                .text(
                    $t('Loading your cart...')
                )
                .show();


            $.ajax({
                url: cartApiUrl,
                type: 'GET',
                dataType: 'json',
                cache: false
            })

                .done(function (response) {

                    latestCartPayload =
                        response;


                    renderCart(
                        response
                    );


                    if (
                        response &&
                        response.customer &&
                        response.customer.email
                    ) {

                        currentCustomer =
                            response.customer;
                    }


                    var storedCustomer =
                        getStoredCustomer();


                    if (
                        storedCustomer &&
                        storedCustomer.email
                    ) {

                        currentCustomer =
                            storedCustomer;


                        $root
                            .find(
                                '[data-cloudright-email-input]'
                            )
                            .val(
                                storedCustomer.email
                            );
                    }

                })

                .fail(function () {

                    $loading
                        .text(
                            $t(
                                'Unable to load your cart. Please try again.'
                            )
                        )
                        .show();


                    showError(
                        $t(
                            'Unable to load your cart. Please try again.'
                        )
                    );
                });
        }


        function renderCart(response) {

            var $items =
                $root.find(
                    '[data-cloudright-summary-items]'
                );


            var $loading =
                $root.find(
                    '[data-cloudright-cart-loading]'
                );


            var $totals =
                $root.find(
                    '[data-cloudright-summary-totals]'
                );


            var $grandTotal =
                $root.find(
                    '[data-cloudright-grand-total]'
                );


            $items.empty();


            if (
                !response ||
                !response.items ||
                !response.items.length
            ) {

                $items.append(
                    '<div class="cloudright-empty-cart">' +
                    escapeHtml(
                        $t('Your cart is empty.')
                    ) +
                    '</div>'
                );


                $loading.hide();

                $totals.hide();

                $grandTotal.text('—');

                return;
            }


            $.each(
                response.items,
                function (index, item) {

                    var imageHtml = '';


                    if (item.image_url) {

                        imageHtml =
                            '<img src="' +
                            escapeHtml(
                                item.image_url
                            ) +
                            '" alt="' +
                            escapeHtml(
                                item.name
                            ) +
                            '" class="cloudright-item-image">';
                    }


                    var rowTotal =
                        getFormattedTotal(
                            item.row_total
                        );


                    var itemHtml =

                        '<div class="cloudright-preview-item">' +

                            '<div class="cloudright-preview-image">' +
                                imageHtml +
                                '<span class="cloudright-preview-qty">' +
                                    escapeHtml(item.qty) +
                                '</span>' +
                            '</div>' +

                            '<div class="cloudright-preview-info">' +

                                '<div class="cloudright-preview-name">' +
                                    escapeHtml(
                                        item.name
                                    ) +
                                '</div>' +

                                '<div class="cloudright-preview-meta">' +
                                    escapeHtml(
                                        $t('Qty: ')
                                    ) +
                                    escapeHtml(
                                        item.qty
                                    ) +
                                '</div>' +

                            '</div>' +

                            '<div class="cloudright-preview-total">' +
                                escapeHtml(
                                    rowTotal
                                ) +
                            '</div>' +

                        '</div>';


                    $items.append(
                        itemHtml
                    );
                }
            );


            $loading.hide();


            if (
                response.totals &&
                response.totals.grand_total
            ) {

                $grandTotal.text(
                    getFormattedTotal(
                        response.totals.grand_total
                    )
                );

                $totals.show();

            } else {

                $grandTotal.text('—');

                $totals.hide();
            }
        }


        /* =========================================================
         * ACCOUNT
         * ========================================================= */

        function handleAccountSubmit(event) {

            event.preventDefault();

            hideError();


            var email =
                $.trim(
                    $root
                        .find(
                            '[data-cloudright-email-input]'
                        )
                        .val()
                );


            if (!email) {

                showError(
                    $t(
                        'Please enter your email address.'
                    )
                );

                return false;
            }


            var emailPattern =
                /^[^\s@]+@[^\s@]+\.[^\s@]+$/;


            if (
                !emailPattern.test(email)
            ) {

                showError(
                    $t(
                        'Please enter a valid email address.'
                    )
                );

                return false;
            }


            var $button =
                $root.find(
                    '[data-cloudright-continue]'
                );


            setButtonLoading(
                $button,
                $t('Continuing...')
            );


            currentCustomer = {
                email: email
            };


            saveCustomer(
                currentCustomer
            );


            populatePaymentStep();


            showStep(
                'payment'
            );


            resetButton(
                $button,
                $t('Continue')
            );


            return false;
        }


        function populatePaymentStep() {

            var customer =
                currentCustomer || {};


            var customerName =
                customer.name ||
                'CloudRight Customer';


            $root
                .find(
                    '[data-cloudright-customer-name]'
                )
                .text(
                    customerName
                );


            $root
                .find(
                    '[data-cloudright-customer-email]'
                )
                .text(
                    customer.email || '—'
                );


            renderPaymentCart();
        }


        /* =========================================================
         * PAYMENT CART
         * ========================================================= */

        function renderPaymentCart() {

            var $items =
                $root.find(
                    '[data-cloudright-payment-items]'
                );


            var $total =
                $root.find(
                    '[data-cloudright-payment-total]'
                );


            $items.empty();


            if (
                !latestCartPayload ||
                !latestCartPayload.items ||
                !latestCartPayload.items.length
            ) {

                $items.append(
                    '<div class="cloudright-empty-cart">' +
                    escapeHtml(
                        $t('Your cart is empty.')
                    ) +
                    '</div>'
                );


                $total.text('—');

                return;
            }


            $.each(
                latestCartPayload.items,
                function (index, item) {

                    var imageHtml = '';


                    if (item.image_url) {

                        imageHtml =
                            '<img src="' +
                            escapeHtml(
                                item.image_url
                            ) +
                            '" alt="' +
                            escapeHtml(
                                item.name
                            ) +
                            '" class="cloudright-item-image">';
                    }


                    var rowTotal =
                        getFormattedTotal(
                            item.row_total
                        );


                    var itemHtml =

                        '<div class="cloudright-cart-item">' +

                            '<div class="cloudright-item-image-wrapper">' +
                                imageHtml +
                                '<span class="cloudright-preview-qty">' +
                                    escapeHtml(item.qty) +
                                '</span>' +
                            '</div>' +

                            '<div class="cloudright-item-details">' +

                                '<div class="cloudright-item-name">' +
                                    escapeHtml(
                                        item.name
                                    ) +
                                '</div>' +

                                '<div class="cloudright-item-meta">' +
                                    escapeHtml(
                                        $t('Qty: ')
                                    ) +
                                    escapeHtml(
                                        item.qty
                                    ) +
                                '</div>' +

                            '</div>' +

                            '<div class="cloudright-item-price">' +
                                escapeHtml(
                                    rowTotal
                                ) +
                            '</div>' +

                        '</div>';


                    $items.append(
                        itemHtml
                    );
                }
            );


            if (
                latestCartPayload.totals &&
                latestCartPayload.totals.grand_total
            ) {

                $total.text(
                    getFormattedTotal(
                        latestCartPayload
                            .totals
                            .grand_total
                    )
                );

            } else {

                $total.text('—');
            }
        }


        /* =========================================================
         * CHANGE ACCOUNT
         * ========================================================= */

        function handleChangeAccount(event) {

            event.preventDefault();

            hideError();


            var customer =
                currentCustomer || {};


            $root
                .find(
                    '[data-cloudright-email-input]'
                )
                .val(
                    customer.email || ''
                );


            showStep(
                'account'
            );


            return false;
        }


        /* =========================================================
         * PAYMENT
         * ========================================================= */

        function handlePay(event) {

            event.preventDefault();

            hideError();


            if (
                !currentCustomer ||
                !currentCustomer.email
            ) {

                showStep(
                    'account'
                );


                showError(
                    $t(
                        'Please enter your email address.'
                    )
                );


                return false;
            }


            var $button =
                $root.find(
                    '[data-cloudright-pay]'
                );


            setButtonLoading(
                $button,
                $t('Processing...')
            );


            var transactionId =
                'CLOUDRIGHT-DUMMY-' +
                Date.now();


            var customerName =
                currentCustomer.name ||
                '';


            var customerPhone =
                currentCustomer.phone ||
                '';


            currentCustomer.name =
                customerName;


            currentCustomer.phone =
                customerPhone;


            saveCustomer(
                currentCustomer
            );


            $.ajax({

                url: orderApiUrl,

                type: 'POST',

                contentType:
                    'application/json',

                dataType:
                    'json',

                data:
                    JSON.stringify({

                        email:
                            currentCustomer.email,

                        name:
                            customerName,

                        phone:
                            customerPhone,

                        transactionId:
                            transactionId
                    })
            })

                .done(function (response) {

                    resetButton(
                        $button,
                        $t('Pay Securely')
                    );


                    if (
                        response &&
                        response.success === false
                    ) {

                        showError(
                            response.message ||
                            $t(
                                'Payment could not be completed. Please try again.'
                            )
                        );

                        return;
                    }


                    /*
                     * Payment and order creation succeeded.
                     *
                     * Magento's checkout session was prepared
                     * by OrderManagement.php. Redirect to the
                     * standard Magento Order Received / Thank You
                     * page instead of showing the custom
                     * confirmation step.
                     */

                    if (successPageUrl) {

                        window.location.href =
                            successPageUrl;

                        return;
                    }


                    /*
                     * Fallback in case the success URL was not
                     * provided by Magento.
                     */

                    window.location.href =
                        '/checkout/onepage/success/';
                })

                .fail(function (xhr) {

                    resetButton(
                        $button,
                        $t('Pay Securely')
                    );


                    var message =
                        $t(
                            'Payment could not be completed. Please try again.'
                        );


                    if (
                        xhr.responseJSON &&
                        xhr.responseJSON.message
                    ) {

                        message =
                            xhr.responseJSON.message;
                    }


                    showError(
                        message
                    );
                });


            return false;
        }


        /* =========================================================
         * CONFIRMATION
         * ========================================================= */

        function renderConfirmation(response) {

            var orderNumber = '';


            if (response) {

                orderNumber =
                    response.order_increment_id ||
                    response.order_number ||
                    response.increment_id ||
                    response.orderId ||
                    '';
            }


            $root
                .find(
                    '[data-cloudright-order-number]'
                )
                .text(
                    orderNumber || '—'
                );


            $root
                .find(
                    '[data-cloudright-payment-status]'
                )
                .text(
                    $t('Paid')
                );
        }


        /* =========================================================
         * CONTINUE SHOPPING
         * ========================================================= */

        function handleContinueShopping(event) {

            event.preventDefault();

            closeModal();


            window.location.href =
                cartPageUrl;


            return false;
        }


        /* =========================================================
         * CANCEL
         * ========================================================= */

        function handleCancel(event) {

            event.preventDefault();

            closeModal();

            return false;
        }


        /* =========================================================
         * CHECKOUT BUTTON DETECTION
         * ========================================================= */

        function isCloudRightCheckoutButton(
            target
        ) {

            if (!target) {
                return false;
            }


            var $target =
                $(target);


            /*
             * First check the normal DOM hierarchy.
             */

            if (
                $target.closest(
                    [
                        'button[data-role="proceed-to-checkout"]',
                        '#top-cart-btn-checkout',
                        '.cloudright-mini-cart-checkout',
                        '[data-cloudright-mini-checkout="1"]'
                    ].join(', ')
                ).length > 0
            ) {
                return true;
            }


            /*
             * Magento's minicart is Knockout-driven.
             *
             * The event target can sometimes come through a
             * different DOM path. Use composedPath() when
             * available so the minicart checkout button is
             * detected reliably.
             */

            if (
                typeof target.composedPath === 'function'
            ) {

                var path =
                    target.composedPath();


                for (
                    var index = 0;
                    index < path.length;
                    index++
                ) {

                    var node =
                        path[index];


                    if (
                        node &&
                        node.nodeType === 1
                    ) {

                        if (
                            node.id ===
                            'top-cart-btn-checkout'
                        ) {
                            return true;
                        }


                        if (
                            node.matches &&
                            node.matches(
                                [
                                    'button[data-role="proceed-to-checkout"]',
                                    '.cloudright-mini-cart-checkout',
                                    '[data-cloudright-mini-checkout="1"]'
                                ].join(', ')
                            )
                        ) {
                            return true;
                        }
                    }
                }
            }


            return false;
        }


        function handleCheckoutClick(event) {

            var target =
                event.target;


            if (
                !isCloudRightCheckoutButton(
                    target
                )
            ) {
                return;
            }


            /*
             * Prevent Magento's normal checkout
             * redirect and minicart handler.
             */

            event.preventDefault();

            event.stopPropagation();

            event.stopImmediatePropagation();


            resetModal();

            openModal();


            return false;
        }


        function interceptCheckout(event) {

            handleCheckoutClick(
                event
            );
        }


        function bindCheckoutButton() {

            if (
                checkoutInterceptorBound
            ) {
                return;
            }


            checkoutInterceptorBound =
                true;


            /*
             * Native capture listener.
             *
             * This is the main handler for the Magento
             * minicart checkout button. It runs before
             * Magento's Knockout click handler.
             */

            document.addEventListener(
                'click',
                interceptCheckout,
                true
            );


            /*
             * Delegated jQuery listener.
             *
             * Keep this for dynamically-created checkout
             * buttons and the existing cart-page flow.
             */

            $(document).on(
                'click.cloudrightCheckout',
                [
                    '#top-cart-btn-checkout',
                    '.cloudright-mini-cart-checkout',
                    '[data-cloudright-mini-checkout="1"]',
                    'button[data-role="proceed-to-checkout"]'
                ].join(', '),
                function (event) {

                    handleCheckoutClick(
                        event
                    );

                    return false;
                }
            );
        }


        /* =========================================================
         * EVENTS
         * ========================================================= */

        function bindEvents() {

            /*
             * Close button
             */

            $root.on(
                'click.cloudright',
                '[data-cloudright-close]',
                handleCancel
            );


            /*
             * Clicking dark overlay closes modal.
             */

            $root.on(
                'click.cloudright',
                function (event) {

                    if (
                        event.target ===
                        $root.get(0)
                    ) {

                        closeModal();
                    }
                }
            );


            /*
             * Account form
             */

            $root.on(
                'submit.cloudright',
                '[data-cloudright-account-form]',
                handleAccountSubmit
            );


            /*
             * Change account
             */

            $root.on(
                'click.cloudright',
                '[data-cloudright-change-account]',
                handleChangeAccount
            );


            /*
             * Cancel payment
             */

            $root.on(
                'click.cloudright',
                '[data-cloudright-cancel]',
                handleCancel
            );


            /*
             * Pay securely
             */

            $root.on(
                'click.cloudright',
                '[data-cloudright-pay]',
                handlePay
            );


            /*
             * Continue shopping
             */

            $root.on(
                'click.cloudright',
                '[data-cloudright-continue-shopping]',
                handleContinueShopping
            );


            /*
             * Checkout interception
             */

            bindCheckoutButton();
        }


        /* =========================================================
         * RESTORE CUSTOMER
         * ========================================================= */

        function restoreCustomer() {

            var storedCustomer =
                getStoredCustomer();


            if (
                storedCustomer &&
                storedCustomer.email
            ) {

                currentCustomer =
                    storedCustomer;


                $root
                    .find(
                        '[data-cloudright-email-input]'
                    )
                    .val(
                        storedCustomer.email
                    );
            }
        }


        /* =========================================================
         * INITIALIZE
         * ========================================================= */

        function initialize() {

            if (initialized) {
                return;
            }


            initialized = true;


            restoreCustomer();


            bindEvents();


            $root
                .attr(
                    'aria-hidden',
                    'true'
                )
                .hide();
        }


        initialize();
    };
});

