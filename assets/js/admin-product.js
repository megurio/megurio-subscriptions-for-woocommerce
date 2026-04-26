jQuery(function($) {
	function megurioToggleFields() {
		var checked = $("#_megurio_is_subscription").is(":checked");
		$(".megurio-subscription-fields").toggle(checked);
	}

	megurioToggleFields();

	$(document.body).on("wc-init-product-type-options", function() {
		megurioToggleFields();
	});

	$(document).on("change", "#_megurio_is_subscription", function() {
		megurioToggleFields();
	});
});
