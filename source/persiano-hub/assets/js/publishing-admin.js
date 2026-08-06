(function ($) {
  'use strict';

  var publishSubmissionStarted = false;

  function updateEventFields() {
    var type = $('#persiano_pub_type').val();
    $('.ph-event-fields').toggleClass('is-visible', type === 'event');
    $('.ph-availability-fields').toggleClass('is-visible', type === 'availability');
    $('.ph-promotion-fields').toggleClass('is-visible', type === 'promotion');
    updateWebsiteBehaviour();
  }

  function updateWebsiteBehaviour() {
    var type = $('#persiano_pub_type').val();
    var productId = $('#persiano_pub_product_id').val();
    var help = $('#ph-product-link-help');
    var note = $('#ph-website-behaviour-note');
    var text = '';
    var productHelp = '';

    if (type === 'dish' || type === 'pantry') {
      if (productId && productId !== '0') {
        text = 'Master product publication: Website uses the selected WooCommerce product. Social channels introduce the same permanent product.';
        productHelp = 'This WooCommerce product remains the source of truth for description, price, stock and ordering.';
      } else {
        text = 'Publishing Website creates one WooCommerce product draft and links it to this campaign. Finish the product before making it live.';
        productHelp = 'Leave empty only when you intentionally want Batchly to create a new product draft.';
      }
    } else if (type === 'availability') {
      text = 'Availability campaign: Website activates the linked product in This Week and updates its date, deadline and optional stock. Social posts point customers to that product.';
      productHelp = 'Required: choose the existing master product that is becoming available.';
    } else if (type === 'promotion') {
      text = 'Promotion campaign: Website creates a dedicated /offers/ landing page. Social posts use that campaign page when it is live, and the landing-page CTA sends customers to the linked product.';
      productHelp = 'Recommended: choose the product customers should ultimately order.';
    } else if (type === 'weekly_menu') {
      text = 'Website creates or updates one Weekly Menu item in Persiano Updates. Menu dishes remain separate WooCommerce products.';
      productHelp = 'Optional: link a product only when the campaign CTA should lead directly to it.';
    } else {
      text = 'Website creates or updates one Persiano Update. Republishing updates the same destination instead of creating duplicates.';
      productHelp = 'Optional: link a product when the campaign call-to-action should lead to a WooCommerce item.';
    }

    help.text(productHelp);
    note.text(text);
  }

  function refreshChannelSelectionUI() {
    $('.ph-channel-checks label').each(function () {
      var label = $(this);
      var checkbox = label.find('input[type="checkbox"]');
      label.toggleClass('is-selected', checkbox.is(':checked'));
    });

    var selected = $('.ph-channel-checks input[type="checkbox"]:checked').length;
    $('.ph-channel-selection-count').remove();
    $('.ph-channel-checks').after(
      $('<div>', {
        'class': 'ph-channel-selection-count',
        text: selected ? selected + ' destination' + (selected === 1 ? '' : 's') + ' selected for the next publish.' : 'No destinations selected. Saving is still safe; publishing will send nothing.'
      })
    );
  }

  function openMediaFrame(options, onSelect) {
    if (typeof window.wp === 'undefined' || !wp.media) {
      window.alert('The WordPress Media Library did not load. Please refresh this page and try again.');
      return;
    }

    var frame = wp.media({
      title: options.title || 'Choose media',
      button: { text: options.buttonText || 'Use this media' },
      library: options.mediaType ? { type: options.mediaType } : undefined,
      multiple: false
    });

    frame.on('select', function () {
      var selection = frame.state().get('selection');
      if (!selection || !selection.first()) {
        return;
      }
      onSelect(selection.first().toJSON());
    });

    frame.open();
  }

  function insertAtCursor(textarea, text) {
    var el = textarea.get(0);
    if (!el) return;
    var start = typeof el.selectionStart === 'number' ? el.selectionStart : el.value.length;
    var end = typeof el.selectionEnd === 'number' ? el.selectionEnd : el.value.length;
    var before = el.value.substring(0, start);
    var after = el.value.substring(end);
    el.value = before + text + after;
    var cursor = start + text.length;
    el.focus();
    if (el.setSelectionRange) el.setSelectionRange(cursor, cursor);
  }

  function setPublishAction(value) {
    var form = $('#post');
    var hidden = form.find('input[name="persiano_hub_publish_action"]');
    if (!hidden.length) {
      hidden = $('<input>', { type: 'hidden', name: 'persiano_hub_publish_action' }).appendTo(form);
    }
    hidden.val(value || '');
  }

  $(document).on('change', '#persiano_pub_type, #persiano_pub_product_id', updateEventFields);
  $(document).on('change', '.ph-channel-checks input[type="checkbox"]', refreshChannelSelectionUI);
  updateEventFields();
  refreshChannelSelectionUI();

  // Every submit button explicitly sets or clears the publishing action. This
  // prevents a previous failed/blocked publish click from leaving a hidden action
  // behind and accidentally sending channels when the user later clicks Save.
  $(document).on('click', '#post button[type="submit"], #post input[type="submit"]', function (event) {
    var button = $(this);
    var action = button.attr('data-ph-publish-action') || '';
    setPublishAction(action);

    if (action) {
      if (publishSubmissionStarted) {
        event.preventDefault();
        return false;
      }
      publishSubmissionStarted = true;
      button.addClass('is-publishing').attr('aria-busy', 'true');
    }
  });

  $(document).on('click', '.ph-select-media', function (event) {
    event.preventDefault();
    var button = $(this);
    var field = button.closest('.ph-media-field');
    var mediaType = button.data('media-type') || 'image';

    openMediaFrame({
      title: mediaType === 'video' ? 'Choose video' : 'Choose image',
      buttonText: 'Use this media',
      mediaType: mediaType
    }, function (attachment) {
      field.find('.ph-media-id').val(attachment.id).trigger('change');
      if (mediaType === 'image') {
        var url = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
        field.find('.ph-media-preview').html('<img src="' + url + '" alt="">');
      } else {
        field.find('.ph-media-preview').text(attachment.filename || attachment.url);
      }
    });
  });

  $(document).on('click', '.ph-insert-content-media', function (event) {
    event.preventDefault();
    openMediaFrame({
      title: 'Add media to shared content',
      buttonText: 'Insert media'
    }, function (attachment) {
      var markup;
      if (attachment.type === 'image') {
        markup = '<img src="' + attachment.url + '" alt="">';
      } else {
        markup = attachment.url;
      }
      insertAtCursor($('#ph_shared_content'), '\n' + markup + '\n');
    });
  });

  $(document).on('click', '.ph-clear-media', function (event) {
    event.preventDefault();
    var field = $(this).closest('.ph-media-field');
    field.find('.ph-media-id').val('').trigger('change');
    field.find('.ph-media-preview').text('No media selected');
  });
})(jQuery);

(function($){
  function carouselIds(){
    var raw=$('#persiano_pub_instagram_carousel_ids').val()||'';
    return raw.split(',').map(function(v){return parseInt(v,10)||0;}).filter(Boolean);
  }
  function setCarouselIds(ids){
    ids=ids.filter(function(v,i,a){return v&&a.indexOf(v)===i;}).slice(0,10);
    $('#persiano_pub_instagram_carousel_ids').val(ids.join(','));
    renderCarousel(ids);
  }
  function renderCarousel(ids){
    var box=$('.ph-carousel-preview').empty();
    $('.ph-carousel-count').text(ids.length ? ids.length+' of 10 selected' : 'Select 2–10 items for a carousel.');
    ids.forEach(function(id){
      var att=wp.media.attachment(id); att.fetch().then(function(){
        var d=att.toJSON(), url=(d.sizes&&d.sizes.thumbnail)?d.sizes.thumbnail.url:d.url;
        var item=$('<div class="ph-carousel-item" draggable="true" data-id="'+id+'"></div>');
        if(d.type==='video'){item.append('<video muted src="'+d.url+'"></video>');}else{item.append('<img src="'+url+'" alt="">');}
        item.append('<button type="button" aria-label="Remove">×</button>'); box.append(item);
      });
    });
  }
  $(document).on('click','.ph-instagram-format-card',function(){
    $('.ph-instagram-format-card').removeClass('is-selected');$(this).addClass('is-selected');$('#persiano_pub_instagram_format').val($(this).data('format'));
  });
  $(document).on('click','.ph-select-carousel-media',function(e){
    e.preventDefault(); if(!wp||!wp.media)return;
    var frame=wp.media({title:'Choose carousel media',button:{text:'Use selected media'},multiple:true});
    frame.on('select',function(){var ids=carouselIds();frame.state().get('selection').each(function(a){ids.push(a.id);});setCarouselIds(ids);});frame.open();
  });
  $(document).on('click','.ph-add-suggested-media',function(){var ids=carouselIds();ids.push(parseInt($(this).data('id'),10));setCarouselIds(ids);});
  $(document).on('click','.ph-carousel-item button',function(){var id=parseInt($(this).parent().data('id'),10);setCarouselIds(carouselIds().filter(function(v){return v!==id;}));});
  $(function(){if($('#persiano_pub_instagram_carousel_ids').length)renderCarousel(carouselIds());});
})(jQuery);
