<?php

namespace App\Services\Platform;

use App\Services\Tax\GstSetupService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Every settings destination, grouped for the Settings workspace and the sidebar search. The sidebar shows one
 * Settings entry; low-level settings live here instead of as dozens of menu links.
 */
final class SettingsCatalog
{
    /** @return list<array{title: string, icon: string, items: list<array{label: string, url: string, keywords: string}>}> */
    public static function sections(NavigationAccess $access): array
    {
        $roleId = (int) Auth::user()->role_id;
        $complianceOn = config('commercial.enabled') && config('compliance.enabled');
        $item = fn (bool $visible, string $label, string $url, string $keywords = '') => $visible ? compact('label', 'url', 'keywords') : null;
        $sections = [
            ['Company & Organization', 'dripicons-store', [
                $item($access->isAdmin, 'Company', route('settings.company'), 'profile gstin address logo'),
                $item($access->allows('warehouse'), 'Branch / Warehouse', route('warehouse.index'), 'godown location'),
                $item($access->isAdmin && $access->context !== null, 'Financial Year', route('company.financial-years.setup'), 'fy period year'),
            ]],
            ['Security & Access', 'dripicons-lock', [
                $item($access->allows('role_permission'), 'Roles & Permissions', route('role.index'), 'access rights'),
                $item($access->allows('users-index'), 'Users', route('user.index'), 'login staff'),
                $item(true, 'My Profile', route('user.profile', ['id' => Auth::id()]), 'password user profile'),
            ]],
            ['Documents', 'dripicons-document', [
                $item($access->allows('sidebar_product'), 'Document Series', route('document-series.index'), 'voucher numbering prefix'),
                $item($access->isAdmin && $complianceOn, 'Tax & Document Settings', url('/compliance/setup'), 'gst e-invoice e-way'),
                $item($access->allows('tax'), GstSetupService::usesGst() ? 'GST & Tax Rates' : 'Tax Rates', GstSetupService::usesGst() ? route('settings.gst') : route('tax.index'), 'gst tax rate hsn'),
                $item($access->allows('invoice_setting'), 'Invoice Printing', route('settings.invoice.index'), 'invoice print format template'),
            ]],
            ['Sales & Purchase', 'dripicons-tags', [
                $item($access->allows('customer_group'), 'Customer Groups', route('customer_group.index'), 'pricing price list'),
                $item($access->allows('discount_plan'), 'Discount Plans', route('discount-plans.index'), 'pricing'),
                $item($access->allows('discount'), 'Discounts', route('discounts.index'), 'pricing offer'),
                $item($access->allows('currency'), 'Currency', route('currency.index'), 'exchange rate'),
            ]],
            ['POS & Retail', 'dripicons-monitor', [
                $item($access->has('sales.pos') && $access->allows('pos_setting'), 'POS Settings', route('setting.pos'), 'point of sale'),
                $item($access->isAdmin || $roleId <= 2, 'Receipt Printers', route('printers.index'), 'thermal printer'),
                $item($access->has('sales.gift_cards') && $access->allows('gift_card'), 'Gift Cards', route('gift_cards.index'), 'promotion'),
                $item($access->has('sales.coupons') && $access->allows('coupon'), 'Coupons', route('coupons.index'), 'promotion'),
                $item($access->allows('reward_point_setting'), 'Reward Points', route('setting.rewardPoint'), 'loyalty'),
            ]],
            ['Communications', 'dripicons-message', [
                $item($access->allows('sms_setting'), 'SMS Settings', route('setting.sms'), 'gateway'),
                $item($access->allows('role_permission'), 'SMS Templates', route('smstemplates.index'), 'message'),
                $item($access->allows('create_sms'), 'Send SMS', route('setting.createSms'), 'message'),
                $item($access->allows('mail_setting'), 'Email Settings', route('setting.mail'), 'mail smtp'),
                $item($access->has('communications.whatsapp'), 'WhatsApp Settings', route('whatsapp.settings'), 'message'),
                $item($access->has('communications.whatsapp'), 'WhatsApp Templates', route('whatsapp.templates'), 'message'),
                $item($access->has('communications.whatsapp'), 'Send WhatsApp', route('whatsapp.send.page'), 'message'),
                $item($access->allows('all_notification'), 'Notifications', route('notifications.index'), 'alerts'),
            ]],
            ['Payments', 'dripicons-card', [
                $item($access->allows('payment_gateway_setting'), 'Payment Gateways', route('setting.gateway'), 'razorpay stripe paypal'),
            ]],
            ['Customization', 'dripicons-toggles', [
                $item($access->allows('custom_field'), 'Custom Fields', route('custom-fields.index'), 'extra field'),
                $item($access->isAdmin || $roleId <= 2, 'Tables', route('tables.index'), 'restaurant'),
                $item($access->allows('language_setting'), 'Languages', route('languages'), 'translation'),
            ]],
            ['Business', 'dripicons-briefcase', [
                $item(config('operations.enabled') && $roleId <= 2, 'Business Profile / Capabilities', url('/operations/profiles'), 'industry textile fmcg timber solar modules'),
                $item($access->allows('addons') && $roleId != 5 && !config('database.connections.zoloerp_landlord'), 'Addons', url('addon-list'), 'modules'),
                $item($access->allows('addons') && $roleId != 5 && $access->has('sales.woocommerce') && Route::has('woocommerce.index'), 'WooCommerce', Route::has('woocommerce.index') ? route('woocommerce.index') : '#', 'ecommerce store'),
            ]],
            ['System', 'dripicons-gear', [
                $item($access->allows('general_setting'), 'General Settings', route('setting.general'), 'site date format'),
                $item($access->allows('barcode_setting'), 'Barcode Settings', route('barcodes.index'), 'label'),
                $item($access->has('hrm.enabled') && $access->allows('hrm_setting'), 'HRM Settings', route('setting.hrm'), 'attendance payroll'),
                $item($access->allows('backup_database'), 'Backup Database', route('setting.backup'), 'export'),
            ]],
        ];

        $visible = [];
        foreach ($sections as [$title, $icon, $items]) {
            $items = array_values(array_filter($items));
            if ($items) $visible[] = compact('title', 'icon', 'items');
        }
        return $visible;
    }
}
