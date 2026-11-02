<?php
/*
 *
 * GSP - GameServer Panel
 * Copyright (C) 2008 - 2017 The GSP Development Team
 *
 *
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */

if (!defined('LANG_currency')) define('LANG_currency', "Currency");
if (!defined('LANG_available_invoice_types')) define('LANG_available_invoice_types', "Available Invoice Types");
if (!defined('LANG_daily')) define('LANG_daily', "Daily");
if (!defined('LANG_monthly')) define('LANG_monthly', "Monthly");
if (!defined('LANG_annually')) define('LANG_annually', "Annually");
if (!defined('LANG_tax_amount')) define('LANG_tax_amount', "Tax Amount");
if (!defined('LANG_paypal_email')) define('LANG_paypal_email', "PayPal E-mail");
if (!defined('LANG_update_settings')) define('LANG_update_settings', "Update Settings");
if (!defined('LANG_settings_updated')) define('LANG_settings_updated', "Settings Updated.");
if (!defined('LANG_payment_gateway')) define('LANG_payment_gateway', "Payment Gateway");
if (!defined('LANG_paypal')) define('LANG_paypal', "PayPal");
if (!defined('LANG_debug')) define('LANG_debug', "Debug");
if (!defined('LANG_sandbox')) define('LANG_sandbox', "Sandbox");
if (!defined('LANG_currency_not_available_at')) define('LANG_currency_not_available_at', "The currency \"%s\" is not available at %s.");
if (!defined('LANG_your_cart')) define('LANG_your_cart', "Your Cart");
if (!defined('LANG_starting_on')) define('LANG_starting_on', "Starting on");
if (!defined('LANG_slots')) define('LANG_slots', "Slots");
if (!defined('LANG_day')) define('LANG_day', "Day");
if (!defined('LANG_month')) define('LANG_month', "Month");
if (!defined('LANG_year')) define('LANG_year', "Year");
if (!defined('LANG_days')) define('LANG_days', "Days");
if (!defined('LANG_months')) define('LANG_months', "Months");
if (!defined('LANG_years')) define('LANG_years', "Years");
if (!defined('LANG_service_name')) define('LANG_service_name', "Service Name");
if (!defined('LANG_rcon_pass')) define('LANG_rcon_pass', "RCON Password");
if (!defined('LANG_ftp_pass')) define('LANG_ftp_pass', "FTP Password");
if (!defined('LANG_available_ips')) define('LANG_available_ips', "Available IPs");
if (!defined('LANG_max_players')) define('LANG_max_players', "Max. Players");
if (!defined('LANG_invoice_duration')) define('LANG_invoice_duration', "Invoice Duration");
if (!defined('LANG_calculate_price')) define('LANG_calculate_price', "Calculate Price");
if (!defined('LANG_buy')) define('LANG_buy', "BUY");
if (!defined('LANG_back_to_list')) define('LANG_back_to_list', "Back to the list");
if (!defined('LANG_ip')) define('LANG_ip', "IP");
if (!defined('LANG_subtotal')) define('LANG_subtotal', "Subtotal");
if (!defined('LANG_rate')) define('LANG_rate', "Rate");
if (!defined('LANG_total')) define('LANG_total', "Total");
if (!defined('LANG_save')) define('LANG_save', "Save");
if (!defined('LANG_you_need_to')) define('LANG_you_need_to', "You need to");
if (!defined('LANG_register')) define('LANG_register', "Register");
if (!defined('LANG_and')) define('LANG_and', "and");
if (!defined('LANG_log_in')) define('LANG_log_in', "Log In");
if (!defined('LANG_to_purchase_a_service')) define('LANG_to_purchase_a_service', "to purchase a service");
if (!defined('LANG_available_services')) define('LANG_available_services', "Available Services");
if (!defined('LANG_payment_is_pending_of_approval')) define('LANG_payment_is_pending_of_approval', "The payment is pending of approval.");
if (!defined('LANG_back_to_your_cart')) define('LANG_back_to_your_cart', "Go back to your cart.");
if (!defined('LANG_expired')) define('LANG_expired', "Expired");
if (!defined('LANG_removed')) define('LANG_removed', "Removed");
if (!defined('LANG_extended')) define('LANG_extended', "Renewed");
if (!defined('LANG_installation_and_expiration_date')) define('LANG_installation_and_expiration_date', "Installation and expiration date");
if (!defined('LANG_expiration_date')) define('LANG_expiration_date', "Expiration date");
if (!defined('LANG_removal_date')) define('LANG_removal_date', "Removal date");
if (!defined('LANG_installation_date')) define('LANG_installation_date', "Installation date");
if (!defined('LANG_enable_server')) define('LANG_enable_server', "Enable server");
if (!defined('LANG_success')) define('LANG_success', "SUCCESS");
if (!defined('LANG_redirecting_to_game_monitor')) define('LANG_redirecting_to_game_monitor', "Redirecting to Game Monitor...");
if (!defined('LANG_starting_installations')) define('LANG_starting_installations', "Starting the installation...");
if (!defined('LANG_order_id')) define('LANG_order_id', "Order ID");
if (!defined('LANG_home_name')) define('LANG_home_name', "Home Name");
if (!defined('LANG_tax')) define('LANG_tax', "Tax");
if (!defined('LANG_pay_from')) define('LANG_pay_from', "Pay From %s");
if (!defined('LANG_set_as_paid')) define('LANG_set_as_paid', "Set As Paid");
if (!defined('LANG_create_server')) define('LANG_create_server', "Create Server");
if (!defined('LANG_see_invoice')) define('LANG_see_invoice', "See Invoice");
if (!defined('LANG_paid')) define('LANG_paid', "Paid");
if (!defined('LANG_not_paid')) define('LANG_not_paid', "Not Paid");
if (!defined('LANG_procesing_payment')) define('LANG_procesing_payment', "Processing Payment");
if (!defined('LANG_paid_and_installed')) define('LANG_paid_and_installed', "Paid And Installed");
if (!defined('LANG_add_to_cart')) define('LANG_add_to_cart', "Add To Cart");
if (!defined('LANG_cart_id')) define('LANG_cart_id', "Cart ID");
if (!defined('LANG_order_desc')) define('LANG_order_desc', "Order Description");
if (!defined('LANG_remove_from_cart')) define('LANG_remove_from_cart', "Remove From Cart");
if (!defined('LANG_remove_cart')) define('LANG_remove_cart', "Remove Cart");
if (!defined('LANG_there_are_no_orders_in_cart')) define('LANG_there_are_no_orders_in_cart', "There are no orders in this cart.");
if (!defined('LANG_redirecting_to')) define('LANG_redirecting_to', "Redirecting to %s...");
if (!defined('LANG_id')) define('LANG_id', "ID#");
if (!defined('LANG_remote_server')) define('LANG_remote_server', "Remote Server");
if (!defined('LANG_price_hourly')) define('LANG_price_hourly', "Price Hourly");
if (!defined('LANG_price_monthly')) define('LANG_price_monthly', "Price Monthly");
if (!defined('LANG_price_year')) define('LANG_price_year', "Price Year");
if (!defined('LANG_service_image_url')) define('LANG_service_image_url', "Service Image URL");
if (!defined('LANG_remove_service')) define('LANG_remove_service', "Remove Service");
if (!defined('LANG_add_service')) define('LANG_add_service', "Add Service");
if (!defined('LANG_current_services')) define('LANG_current_services', "Current Services");
if (!defined('LANG_max_slot_qty')) define('LANG_max_slot_qty', "Max. Slot Quantity");
if (!defined('LANG_min_slot_qty')) define('LANG_min_slot_qty', "Min. Slot Quantity");
if (!defined('LANG_ftp_account')) define('LANG_ftp_account', "FTP Account");
if (!defined('LANG_select_install_method')) define('LANG_select_install_method', "Select install method");
if (!defined('LANG_url_for_manual_install')) define('LANG_url_for_manual_install', "URL for Manual Installation");
if (!defined('LANG_description')) define('LANG_description', "Description");
if (!defined('LANG_image_url')) define('LANG_image_url', "Image URL");
if (!defined('LANG_access_rights')) define('LANG_access_rights', "Access Rights");
if (!defined('LANG_allow_update')) define('LANG_allow_update', "Allow Update");
if (!defined('LANG_allow_file_management')) define('LANG_allow_file_management', "Allow File Management");
if (!defined('LANG_allow_parameter_usage')) define('LANG_allow_parameter_usage', "Allow Parameter Usage");
if (!defined('LANG_allow_extra_parameters_usage')) define('LANG_allow_extra_parameters_usage', "Allow Extra Parameters Usage");
if (!defined('LANG_allow_ftp_usage')) define('LANG_allow_ftp_usage', "Allow FTP Usage");
if (!defined('LANG_allow_custom_fields')) define('LANG_allow_custom_fields', "Allow Custom Fields");
if (!defined('LANG_enabled')) define('LANG_enabled', "Enabled");
if (!defined('LANG_disabled')) define('LANG_disabled', "Disabled");
if (!defined('LANG_steam')) define('LANG_steam', "Steam");
if (!defined('LANG_rsync')) define('LANG_rsync', "Rsync");
if (!defined('LANG_manual_from_url')) define('LANG_manual_from_url', "Manual From URL");
if (!defined('LANG_business')) define('LANG_business', "Business");
if (!defined('LANG_business_email')) define('LANG_business_email', "Business Email");
if (!defined('LANG_game_server_order')) define('LANG_game_server_order', "Game Server Order");
if (!defined('LANG_item')) define('LANG_item', "Item");
if (!defined('LANG_slot_cost')) define('LANG_slot_cost', "Slot Cost");
if (!defined('LANG_slot_quantity')) define('LANG_slot_quantity', "Slot Quantity");
if (!defined('LANG_order_price')) define('LANG_order_price', "Order Price");
if (!defined('LANG_order')) define('LANG_order', "Order");
if (!defined('LANG_date')) define('LANG_date', "Date");
if (!defined('LANG_price')) define('LANG_price', "Price");
if (!defined('LANG_invoice')) define('LANG_invoice', "Invoice");
if (!defined('LANG_print_invoice')) define('LANG_print_invoice', "Print Invoice");
if (!defined('LANG_extend')) define('LANG_extend', "Renew");
if (!defined('LANG_server_id')) define('LANG_server_id', "Server ID");
if (!defined('LANG_webhookurl')) define('LANG_webhookurl', "Discord Webhook");
if (!defined('LANG_current_coupons')) define('LANG_current_coupons', "Discount Coupons");
if (!defined('LANG_code')) define('LANG_code', "Code");
if (!defined('LANG_coupon_name')) define('LANG_coupon_name', "description");
if (!defined('LANG_discount')) define('LANG_discount', "discount percentage");
if (!defined('LANG_count')) define('LANG_count', "how often can it be used");
if (!defined('LANG_expires')) define('LANG_expires', "Expiration date");
if (!defined('LANG_del_coupon')) define('LANG_del_coupon', "Remove");
if (!defined('LANG_add_coupon')) define('LANG_add_coupon', "Add new coupon");
if (!defined('LANG_active')) define('LANG_active', "Active");
if (!defined('LANG_invoice_due')) define('LANG_invoice_due', "Invoice Due");
if (!defined('LANG_suspended')) define('LANG_suspended', "Suspended");
if (!defined('LANG_renewed')) define('LANG_renewed', "Renewed");
if (!defined('LANG_unavailable')) define('LANG_unavailable', "Unavailable");






?>
