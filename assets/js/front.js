document.addEventListener("submit", function(event) {
	if (!event.target.matches(".megurio-cancel-subscription-form")) {
		return;
	}

	if (!window.confirm("この定期購入をキャンセルします。よろしいですか？")) {
		event.preventDefault();
	}
});
