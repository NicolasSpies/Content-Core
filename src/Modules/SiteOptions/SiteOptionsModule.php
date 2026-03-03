<?php
namespace ContentCore\Modules\SiteOptions;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\SiteOptions\Admin\SiteOptionsAdmin;

class SiteOptionsModule implements ModuleInterface
{
    private ?SiteOptionsAdmin $admin = null;
    public const DATA_OPTION = 'cc_site_options';

    public function init(): void
    {
        if (is_admin()) {
            $this->admin = new SiteOptionsAdmin($this);
            $this->admin->init();
        }
    }

    public function get_admin(): ?SiteOptionsAdmin
    {
        return $this->admin;
    }

    public const SCHEMA_OPTION = 'cc_site_options_schema';

    /**
     * Get the dynamic schema for site options
     */
    public function get_schema(): array
    {
        $schema = get_option(self::SCHEMA_OPTION);
        if (is_array($schema) && !empty($schema)) {
            if (isset($schema['footer'])) {
                unset($schema['footer']);
                update_option(self::SCHEMA_OPTION, $schema);
            }
            return $schema;
        }

        return $this->get_default_schema();
    }

    /**
     * The built-in default schema for site options
     */
    public function get_default_schema(): array
    {
        return [
            'company' => [
                'title' => __('Company Info', 'content-core'),
                'fields' => [
                    'company_name' => [
                        'label' => __('Company Name', 'content-core'),
                        'type' => 'text',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                    'contact_person' => [
                        'label' => __('Contact Person', 'content-core'),
                        'type' => 'text',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                ]
            ],
            'contact' => [
                'title' => __('Contact Details', 'content-core'),
                'fields' => [
                    'email' => [
                        'label' => __('Email Address', 'content-core'),
                        'type' => 'email',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                    'phone' => [
                        'label' => __('Phone Number', 'content-core'),
                        'type' => 'text',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                ]
            ],
            'address' => [
                'title' => __('Address', 'content-core'),
                'fields' => [
                    'street' => [
                        'label' => __('Street & Number', 'content-core'),
                        'type' => 'text',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                    'zip' => [
                        'label' => __('ZIP Code', 'content-core'),
                        'type' => 'text',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                    'city' => [
                        'label' => __('City', 'content-core'),
                        'type' => 'text',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                    'country' => [
                        'label' => __('Country', 'content-core'),
                        'type' => 'text',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                ]
            ],
            'social' => [
                'title' => __('Social Media', 'content-core'),
                'fields' => [
                    'instagram_url' => [
                        'label' => __('Instagram URL', 'content-core'),
                        'type' => 'url',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                    'facebook_url' => [
                        'label' => __('Facebook URL', 'content-core'),
                        'type' => 'url',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                    'linkedin_url' => [
                        'label' => __('LinkedIn URL', 'content-core'),
                        'type' => 'url',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                ],
            ],
        ];
    }

    /**
     * Get the schema with labels translated into the requested language
     */
    public function get_localized_schema(string $lang): array
    {
        unset($lang);
        return $this->get_schema();
    }

    /**
     * Update the Site Options schema
     */
    public function update_schema(array $schema): void
    {
        update_option(self::SCHEMA_OPTION, $schema);
    }

    /**
     * Reset schema to the default hardcoded template
     */
    public function reset_schema(): void
    {
        delete_option(self::SCHEMA_OPTION);
    }

    public function get_options(string $lang = ''): array
    {
        $options = get_option(self::DATA_OPTION, null);
        if (is_array($options)) {
            return $options;
        }

        $candidates = [];
        if ($lang !== '') {
            $candidates[] = sanitize_key($lang);
        }
        $candidates[] = 'de';
        $candidates[] = 'en';
        $candidates[] = 'fr';
        $candidates[] = 'it';
        $candidates = array_values(array_unique(array_filter($candidates)));

        foreach ($candidates as $code) {
            $legacy = get_option("cc_site_options_{$code}", []);
            if (is_array($legacy) && !empty($legacy)) {
                update_option(self::DATA_OPTION, $legacy);
                return $legacy;
            }
        }

        return [];
    }
}
