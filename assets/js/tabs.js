/**
 * Scripts específicos para as tabs
 *
 */

/* global jQuery, window */

jQuery(document).ready(function ($) {
	// Elementos do DOM.
	const tabsContainer = $('.codir2me-tabs');
	const activeTab = $('.codir2me-tabs a.active');

	// Função para verificar a necessidade de rolagem.
	function checkScrollable() {
		const tabsWidth = tabsContainer[0].scrollWidth;
		const containerWidth = tabsContainer.parent().width();

		if (tabsWidth > containerWidth) {
			// Temos overflow, habilita rolagem.
			tabsContainer.css('overflow-x', 'auto');

			// Mostrar indicadores visuais.
			if (tabsContainer.scrollLeft() > 0) {
				$('.codir2me-tabs-container').addClass('show-left-fade');
			} else {
				$('.codir2me-tabs-container').removeClass('show-left-fade');
			}

			if (tabsContainer.scrollLeft() + containerWidth < tabsWidth) {
				$('.codir2me-tabs-container').addClass('show-right-fade');
			} else {
				$('.codir2me-tabs-container').removeClass('show-right-fade');
			}
		} else {
			// Não há overflow, desabilita rolagem.
			tabsContainer.css('overflow-x', 'hidden');
			$('.codir2me-tabs-container').removeClass(
				'show-left-fade show-right-fade'
			);
		}
	}

	// Função para centralizar a aba ativa quando há rolagem.
	function scrollToActiveTab() {
		// Verificar se temos overflow.
		const tabsWidth = tabsContainer[0].scrollWidth;
		const containerWidth = tabsContainer.parent().width();

		if (tabsWidth > containerWidth && activeTab.length) {
			const tabsScrollLeft = tabsContainer.scrollLeft();
			const activeTabLeft = activeTab.position().left;
			const activeTabWidth = activeTab.outerWidth();

			// Centralizar aba ativa.
			const scrollTo =
				tabsScrollLeft +
				activeTabLeft -
				containerWidth / 2 +
				activeTabWidth / 2;
			tabsContainer.scrollLeft(scrollTo);
		}
	}

	// Verificar rolagem no carregamento e redimensionamento.
	checkScrollable();
	$(window).on('resize', function () {
		checkScrollable();
		scrollToActiveTab();
	});

	// Centralizar aba ativa no carregamento.
	setTimeout(scrollToActiveTab, 100);

	// Atualizar indicadores visuais durante a rolagem.
	tabsContainer.on('scroll', function () {
		checkScrollable();
	});

	// Permitir rolagem com roda do mouse em desktop.
	tabsContainer.on('wheel', function (e) {
		const tabsWidth = tabsContainer[0].scrollWidth;
		const containerWidth = tabsContainer.parent().width();

		if (tabsWidth > containerWidth) {
			if (e.originalEvent.deltaY !== 0) {
				e.preventDefault();
				tabsContainer.scrollLeft(
					tabsContainer.scrollLeft() + e.originalEvent.deltaY
				);
			}
		}
	});

	// Exibir/ocultar a seção de tamanhos de miniaturas com base na seleção.
	$('input[name="codir2me_thumbnail_option"]').change(function () {
		if ($(this).val() === 'selected') {
			$('#codir2me-thumbnail-sizes').slideDown(300);
		} else {
			$('#codir2me-thumbnail-sizes').slideUp(300);
		}
	});

	// Botões para selecionar/desmarcar todos os tamanhos.
	$('#codir2me-select-all-thumbnails').click(function (e) {
		e.preventDefault();
		$('.codir2me-thumbnail-size input[type="checkbox"]').prop(
			'checked',
			true
		);
	});

	$('#codir2me-deselect-all-thumbnails').click(function (e) {
		e.preventDefault();
		$('.codir2me-thumbnail-size input[type="checkbox"]').prop(
			'checked',
			false
		);
	});

	// Auto-continuar para o próximo lote após 3 segundos se necessário.
	if ($('.codir2me-continue-form').length > 0) {
		const urlParams = new URLSearchParams(window.location.search);

		if (urlParams.get('auto_continue') === '1') {
			setTimeout(function () {
				$('form.codir2me-continue-form button[name="process_batch"]')
					.closest('form')
					.submit();
			}, 3000);
		} else if (urlParams.get('auto_continue_images') === '1') {
			setTimeout(function () {
				$(
					'form.codir2me-continue-form button[name="process_images_batch"]'
				)
					.closest('form')
					.submit();
			}, 3000);
		}
	}
});