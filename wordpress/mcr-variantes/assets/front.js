/* MCR Variantes: picture swatches for variable products, and the weight picker for products sold by weight. */
(function ($) {
  'use strict';
  var C = window.MCRV || {};

  // ---------- Swatches ----------
  function wrapSelect(wrap) { return $(wrap).find('select').first(); }
  function sync(form) {
    $(form).find('.mcrv-wrap').each(function () {
      var wrap = $(this), s = wrapSelect(wrap), val = s.val() || '';
      var avail = {};
      s.find('option').each(function () { if (this.value) avail[this.value] = !this.disabled; });
      var chosen = '';
      wrap.find('.mcrv-opt').each(function () {
        var b = $(this), v = String(b.attr('data-value'));
        var on = v === val;
        b.toggleClass('is-on', on).attr('aria-pressed', on ? 'true' : 'false');
        b.toggleClass('is-off', !(v in avail) || !avail[v]);
        if (on) chosen = b.attr('data-label');
      });
      // "Couleur : Melt caramel" next to the attribute name.
      var label = wrap.closest('tr').find('th label, .label label').first();
      if (label.length) {
        var span = label.find('.mcrv-chosen');
        if (!span.length) { span = $('<span class="mcrv-chosen"></span>'); label.append(span); }
        span.text(chosen ? ' : ' + chosen : '');
      }
    });
  }
  $(document).on('click', '.mcrv-opt', function () {
    var b = $(this), wrap = b.closest('.mcrv-wrap'), s = wrapSelect(wrap), form = b.closest('form.variations_form');
    var v = String(b.attr('data-value'));
    if (b.hasClass('is-on')) { s.val('').trigger('change'); return; }
    // Not available with the other choices: start again from this option.
    if (!s.find('option').filter(function () { return this.value === v && !this.disabled; }).length) {
      form.find('.variations select').val('');
      form.trigger('check_variations');
      form.find('.variations select').trigger('change');
    }
    s.val(v).trigger('change');
    if (!s.val()) { s.find('option').prop('disabled', false); s.val(v).trigger('change'); }
  });
  $(document).on('woocommerce_update_variation_values check_variations reset_data found_variation', 'form.variations_form', function () { sync(this); });
  $(document).on('change', 'form.variations_form .mcrv-wrap select', function () { sync($(this).closest('form')); });
  $(function () {
    $('form.variations_form').each(function () {
      $(this).find('.mcrv-wrap select').attr({ tabindex: '-1', 'aria-hidden': 'true' });
      sync(this);
    });
  });

  // ---------- Sold by weight ----------
  function money(v) {
    var n = (Math.round(v * 100) / 100).toFixed(2).replace('.', C.decimal || ',');
    var cur = C.currency || '$';
    return C.pos === 'left' ? cur + n : C.pos === 'left_space' ? cur + ' ' + n : C.pos === 'right' ? n + cur : n + ' ' + cur;
  }
  function weightBox(el) {
    var box = $(el), input = box.find('input[name="mcr_weight"]');
    var price = parseFloat(box.attr('data-price')) || 0, step = parseFloat(box.attr('data-step')) || 0.1;
    var min = parseFloat(box.attr('data-min')) || step, max = parseFloat(box.attr('data-max')) || 0;
    function clean(v) {
      v = parseFloat(String(v).replace(',', '.'));
      if (!(v > 0)) v = min;
      v = Math.round(v / step) * step;
      v = Math.max(min, max ? Math.min(max, v) : v);
      return Math.round(v * 1000) / 1000;
    }
    function paint() {
      var v = parseFloat(String(input.val()).replace(',', '.'));
      box.find('[data-mcrw-total]').text(v > 0 ? money(price * v) : '–');
      box.find('.mcrw__chip').each(function () { $(this).toggleClass('is-on', Math.abs(parseFloat($(this).attr('data-w')) - v) < 1e-6); });
      $(document).trigger('mcrw:change', [v, price * v]);
    }
    box.on('click', '.mcrw__step', function () { input.val(clean((parseFloat(input.val()) || min) + step * parseFloat($(this).attr('data-d')))); paint(); });
    box.on('click', '.mcrw__chip', function () { input.val(clean($(this).attr('data-w'))); paint(); });
    input.on('input', paint).on('change blur', function () { input.val(clean(input.val())); paint(); });
    paint();
  }
  $(function () { $('.mcrw').each(function () { weightBox(this); }); });
})(jQuery);
