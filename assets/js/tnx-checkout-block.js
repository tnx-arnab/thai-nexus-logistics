(function() {
    /**
     * Monitor country changes directly on the DOM
     */
    const initTnxMonitoring = () => {
        const { dispatch, select } = window.wp.data;

        document.addEventListener('change', (event) => {
            if (event.target && event.target.id === 'shipping-country') {
                const country = event.target.value;

                
                // Force a customer update on the server
                if (dispatch && dispatch('wc/store/cart')) {
                    const country = event.target.value;

                    
                    const cartStore = dispatch('wc/store/cart');
                    
                    // Try to set the address explicitly to force a refresh
                    if (cartStore.setShippingAddress) {

                         cartStore.setShippingAddress({
                             country: country,
                             postcode: ' ' // Space to bypass "missing postcode" client-side validation
                         });
                    } else if (cartStore.__experimentalUpdateCustomerData) {

                         cartStore.__experimentalUpdateCustomerData({
                             shipping_address: { country: country, postcode: ' ' }
                         });
                    } else {

                         cartStore.invalidateResolution('getCart');
                    }
                }
            }
        });

        // Register checkout filter if the API is available
        if (window.wc && window.wc.blocksCheckout) {
            const { registerCheckoutFilters } = window.wc.blocksCheckout;
            const tnxShippingFilter = ( shippingRates ) => {
                return shippingRates;
            };

            registerCheckoutFilters( 'tnx-shipping', {
                shippingRates: tnxShippingFilter,
            } );
        }
    };

    // Use wp.domReady if available, otherwise DOMContentLoaded
    if (window.wp && window.wp.domReady) {
        window.wp.domReady(initTnxMonitoring);
    } else {
        document.addEventListener('DOMContentLoaded', initTnxMonitoring);
    }
})();