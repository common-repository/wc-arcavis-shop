jQuery(document).ready(function () {
    jQuery('#start_sync').off('click').on('click', function () {
        startSync();
    });

    function startSync() {
        var confirmaton = confirm('Achtung! Bestehende Produkte und Bestellungen werden gelöscht. Fortfahren?');
        if (confirmaton === true) {
            syncLoop('yes');
        }
    }

    function syncLoop(delete_or_not) {
        jQuery('#arcavis_preloader').show();
        jQuery.ajax({
            url: website_url + "/wp-admin/admin-ajax.php",
            type: 'post',
            data: {
                action: 'arcavis_start_initial_sync',
                delete_or_not: delete_or_not
            },
            success: function (data) {
                if (data === 'continue') {
                    syncLoop('no');
                } else {
                    jQuery('#arcavis_preloader').hide();
                }

            },
            error: function (errorThrown) {
                console.log(errorThrown);
                window.alert("Error:" + errorThrown)
                jQuery('#arcavis_preloader').hide();
            }
        });

    }
});