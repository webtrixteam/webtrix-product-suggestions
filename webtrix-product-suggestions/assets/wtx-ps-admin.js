jQuery(function ($) {
	'use strict';

	var $btn = $('#wtx-ps-add');
	var idx = parseInt($btn.data('next'), 10) || 0;

	$btn.on('click', function () {
		var html = $('#wtx-ps-tpl').html().replace(/__i__/g, idx++);
		$('#wtx-ps-rules').append(html);
		$(document.body).trigger('wc-enhanced-select-init');
	});

	$(document).on('click', '.wtx-ps-remove', function (e) {
		e.preventDefault();
		$(this).closest('.wtx-ps-rule').remove();
	});

	$(document).on('change', '.wtx-ps-all', function () {
		$(this).closest('.wtx-ps-rule').find('.wtx-ps-cats').toggle(!this.checked);
	});
});
