/* MCR Variantes admin: choose a swatch picture from the media library. */
(function ($) {
  'use strict';
  $(document).on('click', '.mcrv-pick__choose', function (e) {
    e.preventDefault();
    var box = $(this).closest('.mcrv-pick');
    var frame = wp.media({ title: 'Image de l’option', library: { type: 'image' }, multiple: false, button: { text: 'Utiliser cette image' } });
    frame.on('select', function () {
      var a = frame.state().get('selection').first().toJSON();
      var url = (a.sizes && a.sizes.thumbnail && a.sizes.thumbnail.url) || a.url;
      box.find('input').val(a.id);
      box.find('img').attr('src', url).show();
      box.find('.mcrv-pick__remove').show();
    });
    frame.open();
  });
  $(document).on('click', '.mcrv-pick__remove', function (e) {
    e.preventDefault();
    var box = $(this).closest('.mcrv-pick');
    box.find('input').val('');
    box.find('img').attr('src', '').hide();
    $(this).hide();
  });
  // Term "add" form is sent by AJAX: clear the picker afterwards.
  $(document).ajaxComplete(function (ev, xhr, settings) {
    if (settings && settings.data && String(settings.data).indexOf('action=add-tag') > -1) {
      $('#addtag .mcrv-pick input').val(''); $('#addtag .mcrv-pick img').hide(); $('#addtag .mcrv-pick__remove').hide();
    }
  });
})(jQuery);
