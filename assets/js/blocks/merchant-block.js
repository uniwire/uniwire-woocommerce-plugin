( function() {
	'use strict';

	var settings = window.wc.wcSettings.getPaymentMethodData( 'merchant_plugin', {} );
	var label = settings.title || 'Bitcoin and other cryptocurrencies';
	var description = settings.description || 'Pay with Bitcoin or other cryptocurrencies.';

	var Content = function() {
		return window.wp.element.createElement( 'div', null, description );
	};

	var Label = function( props ) {
		var PaymentMethodLabel = props.components.PaymentMethodLabel;
		return window.wp.element.createElement( PaymentMethodLabel, { text: label } );
	};

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'merchant_plugin',
		label: window.wp.element.createElement( Label, null ),
		content: window.wp.element.createElement( Content, null ),
		edit: window.wp.element.createElement( Content, null ),
		canMakePayment: function() { return true; },
		ariaLabel: label,
		supports: {
			features: settings.supports || [ 'products' ]
		}
	} );
} )();
