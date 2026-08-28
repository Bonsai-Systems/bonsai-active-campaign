/**
 * Bonsai ActiveCampaign — front-end form submission.
 *
 * Each rendered form posts to admin-ajax.php (action: bac_submit_form). The
 * plugin creates/updates the contact server-side and adds them to the form's
 * list. On success the form is replaced by the ActiveCampaign "thanks" copy.
 */
(function ($) {
	'use strict';

	var config = window.bacForm || {};

	$(document).on('submit.bonsai_ac', '[data-bac-form]', function (event) {
		event.preventDefault();

		var $form   = $(this);
		var $box    = $form.closest('[data-bac-instance]');
		var $thanks = $box.find('[data-bac-thanks]');
		var $error  = $box.find('[data-bac-error]');
		var $submit = $form.find('[type="submit"]');

		if ($form.data('bacBusy')) {
			return;
		}
		$form.data('bacBusy', true);
		$submit.prop('disabled', true).addClass('is-loading');
		$error.attr('hidden', true).text('');

		$.ajax({
			url: config.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: $form.serialize() + '&action=bac_submit_form'
		}).done(function (response) {
			if (response && response.success && response.data && response.data.message) {
				$thanks.html(response.data.message).removeAttr('hidden');
				$form.attr('hidden', true);
				$box.get(0).scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			} else {
				showError(response && response.data && response.data.message);
			}
		}).fail(function (jqXHR) {
			var message = jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message;
			showError(message);
		}).always(function () {
			$form.data('bacBusy', false);
			$submit.prop('disabled', false).removeClass('is-loading');
		});

		function showError(message) {
			$error
				.text(message || (config.i18n && config.i18n.generic) || 'Something went wrong. Please try again.')
				.removeAttr('hidden');
		}
	});
})(jQuery);
