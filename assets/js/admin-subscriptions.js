// Confirm irreversible subscription cancellation from the admin screen.
document.addEventListener('submit', function (event) {
	if (!event.target.matches('.megurio-admin-cancel-form')) {
		return;
	}

	var message = event.target.getAttribute('data-confirm');
	if (message && !window.confirm(message)) {
		event.preventDefault();
	}
});
