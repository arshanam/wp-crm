var wp_crm_quick_reports = [];

google.load("visualization", "1", {
    packages: [ "corechart" ]
}), jQuery(document).bind("wp_crm_user_results", function() {}), jQuery(document).ready(function() {
    jQuery("#wp_crm_text_search").focus(), jQuery(".wp_crm_user_row_actions .wp_crm_user_action[action=reset_password]").live("click", function() {
        var this_button = this, user_id = jQuery(this).attr("user_id"), user_card_wrapper = jQuery(this).closest(".user_card_inner_wrapper"), user_primary = jQuery("li.primary", user_card_wrapper).html();
        jQuery.ajax({
            url: ajaxurl,
            dataType: "json",
            data: {
                action: "wp_crm_quick_action",
                object_id: user_id,
                wp_crm_quick_action: "reset_user_password"
            },
            success: function(result) {
                "true" == result.success && (jQuery(this_button).hasClass("wp_crm_performed_action") || (jQuery(this_button).addClass("wp_crm_performed_action"), 
                jQuery("#performed_actions").show(), jQuery(".wp_crm_quick_report_wrapper .reset_passwords").length || jQuery(".wp_crm_quick_report_wrapper").append('<ul class="reset_passwords"><li class="log_title">Reset Passwords:</li></ul>'), 
                jQuery(".wp_crm_quick_report_wrapper .reset_passwords").append("<li>" + user_primary + "</li>")));
            }
        });
    }), jQuery(".wp_crm_user_row_actions .wp_crm_user_action[action=exclude]").live("click", function() {
        var row_element = jQuery(this).closest("tr"), row = jQuery(row_element).get(0), row_position = wp_list_table.fnGetPosition(row);
        void 0 === wp_list_counts.excluded_users && (wp_list_counts.excluded_users = []), 
        wp_list_table.fnDeleteRow(row_position, function() {}, !1), wp_list_counts.excluded_users.push(user_id), 
        jQuery(row_element).remove(), wp_list_table_rebrand_rows();
    }), jQuery(".wp_crm_visualize_results").click(function() {
        var filters = jQuery("#wp-crm-filter").serialize();
        jQuery.ajax({
            url: ajaxurl,
            context: document.body,
            data: {
                action: "wp_crm_visualize_results",
                filters: filters
            },
            success: function(result) {
                jQuery(".wp_crm_ajax_result").html(result), jQuery(".wp_crm_ajax_result").show("slide", {
                    direction: "down"
                }, 1e3);
            }
        });
    }), jQuery(".wp_crm_export_to_csv").click(function() {
        var filters = jQuery("#wp-crm-filter").serialize();
        wp_crm_csv_download = ajaxurl + "?action=wp_crm_csv_export&" + filters, location = wp_crm_csv_download;
    }), jQuery(".check-column input").change(function() {
        var selected_elements = jQuery(".check-column input:checked").length;
        selected_elements > 0 ? jQuery(".tablenav .wp_crm_bulk").css("display", "inline") : jQuery(".tablenav .wp_crm_bulk").css("display", "none");
    }), jQuery(".wp_crm_bulk select").change(function() {
        var selected = jQuery("option:selected", this).val();
        alert(selected);
    }), jQuery("thead th.check-column input[type=checkbox]").change(function() {
        var wp_result_user_count = 10;
        jQuery(this).is(":checked") ? jQuery(".wp_crm_above_overview_table").html('<div class="updated"><p><span class="wp_crm_link">Select all ' + wp_result_user_count + " users?</span></p></div>") : jQuery(".wp_crm_above_overview_table div").remove();
    });
});