(function ($) {
	'use strict';

	var cfg = window.wtxPS || {};
	var i18n = cfg.i18n || {};

	function fa(s) {
		s = String(s);
		return cfg.fa ? s.replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(d); }) : s;
	}

	function money(n) {
		var d = parseInt(cfg.decimals, 10) || 0;
		var parts = Math.abs(n).toFixed(d).split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, cfg.thousand || ',');
		var num = parts.length > 1 ? parts[0] + (cfg.decimal || '.') + parts[1] : parts[0];
		var out = (cfg.format || '%2$s&nbsp;%1$s')
			.replace('%1$s', cfg.symbol || '')
			.replace('%2$s', fa(num));
		return out;
	}

	/* ---------- checkbox mode ---------- */

	function sync() {
		var $form = $('form.cart').first();
		var chosen = {};

		$form.find('input.wtx-ps-hidden').remove();

		$('.wtx-ps--checkbox').each(function () {
			var $box = $(this), total = 0, count = 0;
			$box.find('.wtx-ps__check:checked').each(function () {
				total += parseFloat($(this).data('price')) || 0;
				count++;
				chosen[this.value] = true;
			});
			$box.find('.wtx-ps__total').prop('hidden', !count).find('strong').html(money(total));
		});

		if ($form.length && !$.isEmptyObject(chosen)) {
			$('<input type="hidden" class="wtx-ps-hidden" name="wtx_ps_nonce">').val(cfg.nonce || '').appendTo($form);
			$.each(chosen, function (id) {
				$('<input type="hidden" class="wtx-ps-hidden" name="wtx_ps_add[]">').val(id).appendTo($form);
			});
		}
	}

	$(document).on('change', '.wtx-ps__check', sync);
	$(sync);
	$(window).on('pageshow', sync);
	// variable products re-render parts of the form; make sure hidden inputs survive.
	$(document.body).on('found_variation reset_data', function () { setTimeout(sync, 0); });

	/* ---------- button mode ---------- */

	function say($box, text, isError, withCart) {
		var $m = $box.find('.wtx-ps__msg').toggleClass('is-error', !!isError).text(text);
		if (withCart && cfg.cart_url) {
			$('<a>').attr('href', cfg.cart_url).text(i18n.cart || '').appendTo($m);
		}
	}

	$(document).on('click', '.wtx-ps__btn', function (e) {
		e.preventDefault();
		var $b = $(this), $box = $b.closest('.wtx-ps');
		if ($b.hasClass('is-loading')) { return; }

		$b.addClass('is-loading');
		$.ajax({
			url: cfg.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: { product_id: $box.data('product'), ids: [$b.data('id')], nonce: cfg.nonce }
		}).done(function (res) {
			if (res && res.success) {
				$b.addClass('is-done').text(i18n.added || '✓');
				say($box, i18n.added || '', false, true);
				$(document.body).trigger('wc_fragment_refresh');
				setTimeout(function () {
					$b.removeClass('is-done').text($b.data('label'));
				}, 2500);
			} else {
				say($box, i18n.error || '', true, false);
			}
		}).fail(function () {
			say($box, i18n.error || '', true, false);
		}).always(function () {
			$b.removeClass('is-loading');
		});
	});

	/* ---------- carousel ---------- */

	$(document).on('click', '.wtx-ps__nav', function () {
		var $wrap = $(this).closest('.wtx-ps__wrap');
		var track = $wrap.find('.wtx-ps__items')[0];
		if (!track) { return; }
		var rtl = window.getComputedStyle(track).direction === 'rtl';
		var next = $(this).hasClass('wtx-ps__nav--next');
		var dir = (next ? 1 : -1) * (rtl ? -1 : 1);
		track.scrollBy({ left: dir * track.clientWidth * 0.8, behavior: 'smooth' });
	});

	/* ---------- fallback placement (themes that skip the standard hooks) ---------- */

	function place() {
		$('.wtx-ps-fallback').each(function () {
			var $wrap = $(this), pos = $wrap.data('position') || 'after_price';
			var $box = $wrap.children('.wtx-ps').first();
			var sel = {
				price: '.summary .price, .entry-summary .price, .product .price, p.price',
				excerpt: '.woocommerce-product-details__short-description',
				cart: 'form.cart'
			};
			var key = /price/.test(pos) ? 'price' : (/excerpt/.test(pos) ? 'excerpt' : 'cart');
			var $a = $(sel[key]).first();
			if (!$a.length) { $a = $(sel.cart).first(); key = 'cart'; pos = 'before_cart'; }
			if (!$a.length) { $a = $(sel.price).first(); pos = 'after_price'; }

			if ($a.length && $box.length) {
				if (/^before/.test(pos)) { $box.insertBefore($a); } else { $box.insertAfter($a); }
				$wrap.remove();
			} else {
				$wrap.show();
			}
		});
	}
	$(place);

})(jQuery);
