jQuery(document).ready(function ($) {
    if (!wp || !wp.media) {
        return;
    }

    $('.cc-site-image-card').each(function () {
        var $card = $(this);
        var $uploadBtn = $card.find('.cc-site-image-upload');
        var $removeBtn = $card.find('.cc-site-image-remove');
        var $preview = $card.find('.cc-site-image-preview');
        var $input = $card.find('.cc-site-image-input');
        var frame;

        $uploadBtn.on('click', function (e) {
            e.preventDefault();

            if (frame) {
                frame.open();
                return;
            }

            frame = wp.media({
                title: wp.i18n ? wp.i18n.__('Select Image', 'content-core') : 'Select Image',
                button: {
                    text: wp.i18n ? wp.i18n.__('Use this image', 'content-core') : 'Use this image'
                },
                multiple: false,
                library: {
                    type: 'image'
                }
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();

                if (attachment.type !== 'image') {
                    var errorMsg = CC_SITE_IMAGES.strings.invalid_selection || 'Please select a valid image.';
                    alert(errorMsg);
                    return;
                }

                $input.val(attachment.id);

                var imgUrl = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
                $preview.html('<img src="' + imgUrl + '" style="max-width: 100%; max-height: 120px; width: auto; height: auto; display: block;" />');

                $removeBtn.show();

                // Change upload button text to Replace slightly dynamically if strings are loaded
                $uploadBtn.text(wp.i18n ? wp.i18n.__('Replace', 'content-core') : 'Replace');
            });

            frame.open();
        });

        $removeBtn.on('click', function (e) {
            e.preventDefault();
            $input.val('0');
            $preview.html('<span style="color: #a7aaad; font-size: 12px;">' + (wp.i18n ? wp.i18n.__('No image selected', 'content-core') : 'No image selected') + '</span>');
            $uploadBtn.text(wp.i18n ? wp.i18n.__('Upload', 'content-core') : 'Upload');
            $(this).hide();
        });
    });
});
