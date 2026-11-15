<?php

/*
 * Registry of editable system settings. The admin UI is generated from this list and only
 * these keys can be written. Types: string, text, bool, color, email, select (with 'options').
 */
return [
    'branding' => [
        'label' => 'Branding',
        'items' => [
            'branding.name' => ['label' => 'Product name', 'type' => 'string', 'max' => 60],
            'branding.short_name' => ['label' => 'Short name', 'type' => 'string', 'max' => 30],
            'branding.support_email' => ['label' => 'Support email', 'type' => 'email', 'max' => 255],
            'branding.primary_color' => ['label' => 'Primary color', 'type' => 'color'],
        ],
    ],
    'site' => [
        'label' => 'Site, SEO, menu and footer',
        'items' => [
            'site.seo_title' => ['label' => 'SEO title (shown in the browser tab and search results)', 'type' => 'string', 'max' => 70],
            'site.seo_description' => ['label' => 'SEO description (about 150 characters)', 'type' => 'text', 'max' => 300],
            'site.seo_keywords' => ['label' => 'SEO keywords (comma-separated)', 'type' => 'string', 'max' => 255],
            'site.og_image' => ['label' => 'Share image URL (https://…)', 'type' => 'string', 'max' => 500],
            'site.allow_indexing' => ['label' => 'Let search engines index public pages', 'type' => 'bool'],
            'site.menu_links' => ['label' => 'Extra menu links — one per line: Label | https://url', 'type' => 'text', 'max' => 1500],
            'site.footer_text' => ['label' => 'Footer text', 'type' => 'string', 'max' => 200],
            'site.footer_links' => ['label' => 'Footer links — one per line: Label | https://url', 'type' => 'text', 'max' => 1500],
        ],
    ],
    'system' => [
        'label' => 'System',
        'items' => [
            'system.signup_enabled' => ['label' => 'New registration on', 'type' => 'bool'],
            'system.default_locale' => ['label' => 'Default language', 'type' => 'select', 'options' => ['bn', 'en']],
            'system.default_currency' => ['label' => 'Default currency', 'type' => 'select', 'options' => ['CAD', 'BDT', 'USD']],
        ],
    ],
    'maintenance' => [
        'label' => 'Maintenance mode',
        'items' => [
            'maintenance.enabled' => ['label' => 'Maintenance mode on', 'type' => 'bool'],
            'maintenance.message' => ['label' => 'Message shown to the user', 'type' => 'text', 'max' => 500],
            'maintenance.allowed_ips' => ['label' => 'Allowed IPs (comma-separated)', 'type' => 'string', 'max' => 500],
        ],
    ],
];
