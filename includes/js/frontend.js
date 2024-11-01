jQuery(document).ready(function () {

    /*jQuery('#billing_email').blur(function(){
        jQuery('body').trigger('update_checkout');

   }); */
    let voucher = jQuery('#arcavis_voucher');
    voucher.off('keyup').on('keyup', function (event) {
        if (voucher.val() !== '' && event.keyCode === 13) {
            jQuery('#arcavis_applied_voucher').val(jQuery('#arcavis_voucher').val()).trigger('change');
        }
    });
    voucher.off('blur').on('blur', function () {
        if (voucher.val() !== '') {
            jQuery('#arcavis_applied_voucher').val(jQuery('#arcavis_voucher').val()).trigger('change');
        }
    });
    jQuery('#arcavis_applied_voucher').off('change').on('change', function () {
        jQuery('body').trigger('update_checkout');
        // Wait until post_check_transaction finished...

        jQuery(document).one('updated_checkout', function () {
            jQuery.ajax({
                url: website_url + "/wp-admin/admin-ajax.php",
                type: 'post',
                data: {
                    action: 'arcavis_get_applied_voucher_code',
                },
                success: function (response) {
                    let data;
                    if (response) {
                        jQuery('#applied_voucher_wrapper').remove();
                        data = JSON.parse(response);
                        voucher.after("<div id='applied_voucher_wrapper'><h5>Gutschein erfolgreich hinzugefügt.</h5>" + data.voucher_code + ' <a id="arcavis_voucher_remove_link" class="error" href="javascript:void(0)"> X </a></div>');
                        jQuery('#arcavis_voucher_remove_link').one("click", function () {
                            voucher.val('');
                            jQuery('#arcavis_applied_voucher').val('');
                            jQuery('#applied_voucher_wrapper').remove();
                            jQuery('body').trigger('update_checkout');
                        });
                    } else {
                        alert('Gutschein ungültig.');
                        voucher.val('');
                        jQuery('#applied_voucher_wrapper').remove();
                    }
                },
                error: function (errorThrown) {
                    console.log(errorThrown);
                }
            });
        });


    });


});