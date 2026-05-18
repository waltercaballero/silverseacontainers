jQuery(document).ready(function ($) {
    $('.lcs-upload-image').click(function (e) {
        e.preventDefault();

        const button = $(this);
        const targetInput = $('#' + button.data('target'));
        const targetPreview = $('#lcs_flag_preview_' + button.data('target').split('_')[2]);

        const customUploader = wp.media({
            title: 'Seleccionar Imagen',
            button: { text: 'Usar esta imagen' },
            multiple: false,
        }).on('select', function () {
            const attachment = customUploader.state().get('selection').first().toJSON();
            targetInput.val(attachment.url);
            targetPreview.attr('src', attachment.url).show();
        }).open();
    });
});
