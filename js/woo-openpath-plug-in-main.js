(function($){
	var credit_card_selector    = "#openpath-card-number";
	var payment_method_selector = '#payment_method_openpathpay[name="payment_method"]';
	var loader                  = '<div class="blockUI openpath-blockUI" style="display:none"></div><div class="blockUI blockOverlay openpath-blockUI" style="z-index: 1000; border: none; margin: 0px; padding: 0px; width: 100%; height: 100%; top: 0px; left: 0px; background: rgb(255, 255, 255); opacity: 0.6; cursor: default; position: absolute;"></div>';

	$(document).ready(function(){
		// register listener to whenever ccnumber or zip changes
		register_interpayments_events();
	
		// send client's fingerprint information to server (screen resolution and time zone)
		sendClientFingerPrintInformation();
		
	});	
	
	function getTimeZone() {
		
		var offset = new Date().getTimezoneOffset();
		var o = Math.abs(offset);
		return (offset < 0 ? '+' : '-') + // + or - from UTC
			   String(Math.floor(o / 60)).padStart(2, '0') + ':' + // hours
			   String(o % 60).padStart(2, '0'); // minutes
	}
	
	function getScreenResolution(){
		
        var width = screen.width;
        var height = screen.height;
			
        return width+'x'+height;
    }
	
	function sendClientFingerPrintInformation() {

		var timeZone = getTimeZone();
		var screenResolution = getScreenResolution();
		
		$.ajax({
			url: woo_openpath_plug_in_main_params.admin_ajax_url,
			type: 'POST',
			data: {
				action: 'handle_client_fingerprint_info',
				time_zone: timeZone,
				screen_resolution: screenResolution
			},
			success: function(response) {
				console.log('Client information sent successfully');
			}
		});
	}

	function register_interpayments_events(){
		$('body').on('change',payment_method_selector,handle_on_method_changed);	
		$("body").on('change', credit_card_selector, handle_transaction_fee_change); 
		$('#billing_postcode').on('change', handle_transaction_fee_change);
		handle_on_method_changed();

		function handle_transaction_fee_change() {
			var payment_method = $(payment_method_selector);

			if (!payment_method.prop('checked')) {
				return;
			}

			var card_number = $(credit_card_selector).val().replace(/\s+/g, '');

			if (card_number && card_number.length > 4) {
				var validateResult = $(credit_card_selector).validateCreditCard();
				var postcode       = $("#ship-to-different-address-checkbox").val() == 2 ? $("#shipping_postcode").val() : $("#billing_postcode").val();
				
				if (validateResult.valid === true && postcode) {
					set_transaction_fee(card_number, postcode);
				}
			}
		}

		function handle_on_method_changed(){
			$(credit_card_selector).val("");
			remove_transaction_fee();
		}
	}
	
	function set_transaction_fee(ccnumber,zip){
		var remove_loader = display_loader();
		var nonce         = $('#woocommerce_openpathpay_nonce').val();
		var data          = {
			action: 'woo_openpath_plug_in_add_transaction_fee_ajax',
			woocommerce_openpathpay_nonce: nonce,
			nicn: ccnumber.substring(0,6),
			region: zip
		};
	   $.post( woo_openpath_plug_in_main_params.admin_ajax_url, data, 
		function( response )
		{
			$( 'body' ).trigger( 'update_checkout' );
			remove_loader();
		})
		.fail(function() {
			console.log('There was an error calculating surcharge for this credit card and address.');
			remove_loader();
		});
	}

	function remove_transaction_fee(){
		var remove_loader = display_loader();
		var data          = {
			action: 'woo_openpath_plug_in_remove_transaction_fee_ajax'
		};

		$.post( woo_openpath_plug_in_main_params.admin_ajax_url, data, function( response )
		{
			$( 'body' ).trigger( 'update_checkout' );
			remove_loader();
		})
		.fail(function() {
			console.log('There was an error setting surcharge for this credit card and address.');
			remove_loader();
		});
	}

	function display_loader() {
		$("#order_review").append(loader);
		$("#order_review").css({"position":"relative"});
		return () => { $(".openpath-blockUI").remove(); $("#order_review").css({"position":""}); }
	}
	
})(jQuery);