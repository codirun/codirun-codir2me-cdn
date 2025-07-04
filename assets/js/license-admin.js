/**
 * JavaScript para a página de licenciamento do plugin R2 Static & Media CDN
 *
 * @param {Object} $ - jQuery object
 */

/* global jQuery, codir2me_license_vars, confirm, location */

(function ($) {
	'use strict';

	$(document).ready(function () {
		// Toggle accordion.
		$('.codir2me-accordion-header').click(function () {
			$(this)
				.find('.dashicons')
				.toggleClass(
					'dashicons-arrow-down-alt2 dashicons-arrow-up-alt2'
				);
			$(this).next('.codir2me-accordion-content').slideToggle(300);
		});

		// Ativar licença.
		$('#codir2me-activate-license-form').submit(function (e) {
			e.preventDefault();
			const licenseKey = $('#codir2me_license_key').val();
			const licenseEmail = $('#codir2me_license_email').val();

			if (!licenseKey) {
				$('#codir2me-license-message')
					.html(
						'<p class="error">' +
							codir2me_license_vars.error_empty_license +
							'</p>'
					)
					.show();
				return;
			}

			if (!licenseEmail || !isValidEmail(licenseEmail)) {
				$('#codir2me-license-message')
					.html(
						'<p class="error">' +
							codir2me_license_vars.error_invalid_email +
							'</p>'
					)
					.show();
				return;
			}

			$('#codir2me-activate-license-btn').prop('disabled', true);
			$('.codir2me-license-spinner').show();
			$('#codir2me-license-message').hide();

			$.ajax({
				url: codir2me_license_vars.ajaxurl,
				type: 'POST',
				data: {
					action: 'codir2me_activate_license',
					license_key: licenseKey,
					license_email: licenseEmail,
					nonce: $('#codir2me_license_nonce').val(),
				},
				success(response) {
					$('.codir2me-license-spinner').hide();
					$('#codir2me-activate-license-btn').prop('disabled', false);

					if (response.success) {
						$('#codir2me-license-message').html('<p class="success">' + response.data.message + '</p>').show();
						// Recarregar a página após 2 segundos.
						setTimeout(
							function () {
								location.reload();
							},
							2000
						);
					} else {
						$('#codir2me-license-message').html('<p class="error">' + response.data.message + '</p>').show();
					}
				},
				error() {
					$('.codir2me-license-spinner').hide();
					$('#codir2me-activate-license-btn').prop('disabled', false);
					$('#codir2me-license-message').html('<p class="error">' + codir2me_license_vars.error_server + '</p>').show();
				},
			});
		});

		// Desativar licença.
		$('#codir2me-deactivate-license-btn').click(function () {
			if (!confirm(codir2me_license_vars.confirm_deactivate)) {
				return;
			}

			$(this).prop('disabled', true);
			$('.codir2me-license-spinner').show();
			$('#codir2me-license-message').hide();

			$.ajax({
				url: codir2me_license_vars.ajaxurl,
				type: 'POST',
				data: {
					action: 'codir2me_deactivate_license',
					nonce: $('#codir2me_license_nonce').val(),
				},
				success: function (response) {
					$('.codir2me-license-spinner').hide();
					$('#codir2me-deactivate-license-btn').prop(
						'disabled',
						false
					);

					if (response.success) {
						$('#codir2me-license-message')
							.html(
								'<p class="success">' +
									response.data.message +
									'</p>'
							)
							.show();
						// Recarregar a página após 2 segundos.
						setTimeout(function () {
							location.reload();
						}, 2000);
					} else {
						$('#codir2me-license-message')
							.html(
								'<p class="error">' +
									response.data.message +
									'</p>'
							)
							.show();
					}
				},
				error() {
					$('.codir2me-license-spinner').hide();
					$('#codir2me-deactivate-license-btn').prop('disabled', false);
					$('#codir2me-license-message').html('<p class="error">' + codir2me_license_vars.error_server + '</p>').show();
				},
			});
		});

		// Trocar domínio.
		$('#codir2me-change-domain-form').submit(function (e) {
			e.preventDefault();
			const newDomain = $('#codir2me_new_domain').val();

			if (!newDomain) {
				$('#codir2me-domain-message')
					.html(
						'<p class="error">' +
							codir2me_license_vars.error_empty_domain +
							'</p>'
					)
					.show();
				return;
			}

			if (
				!confirm(
					codir2me_license_vars.confirm_domain_change.replace(
						'%s',
						newDomain
					)
				)
			) {
				return;
			}

			$('#codir2me-change-domain-btn').prop('disabled', true);
			$('.codir2me-domain-spinner').show();
			$('#codir2me-domain-message').hide();

			$.ajax({
				url: codir2me_license_vars.ajaxurl,
				type: 'POST',
				data: {
					action: 'codir2me_change_domain',
					new_domain: newDomain,
					nonce: $('#codir2me_domain_nonce').val(),
				},
				success(response) {
					$('.codir2me-domain-spinner').hide();
					$('#codir2me-change-domain-btn').prop('disabled', false);

					if (response.success) {
						$('#codir2me-domain-message').html('<p class="success">' + response.data.message + '</p>').show();
						// Recarregar a página após 2 segundos.
						setTimeout(
							function () {
								location.reload();
							},
							2000
						);
					} else {
						$('#codir2me-domain-message').html('<p class="error">' + response.data.message + '</p>').show();
					}
				},
				error() {
					$('.codir2me-domain-spinner').hide();
					$('#codir2me-change-domain-btn').prop('disabled', false);
					$('#codir2me-domain-message').html('<p class="error">' + codir2me_license_vars.error_server + '</p>').show();
				},
			});
		});

		// Função auxiliar para validar email.
		function isValidEmail(email) {
			const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
			return pattern.test(email);
		}

		// Garantir que a notificação permaneça abaixo do botão de verificação.
		if (
			$('#codir2me-license-verify-result').length &&
			$('form[action*="codir2me_force_license_check"]').length
		) {
			// Mover a notificação após o formulário.
			const licenseResult = $('#codir2me-license-verify-result');
			const verifyForm = $('form[action*="codir2me_force_license_check"]');

			licenseResult.insertAfter(verifyForm);

			// Scroll para a notificação.
			$('html, body').animate(
				{
					scrollTop: licenseResult.offset().top - 100,
				},
				500
			);
		}
	});
})(jQuery);