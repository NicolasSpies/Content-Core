<?php
namespace ContentCore\Modules\SiteOptions;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\SiteOptions\Admin\SiteOptionsAdmin;

class SiteOptionsModule implements ModuleInterface
{
    private ?SiteOptionsAdmin $admin = null;

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
    public const GROUP_ID_OPTION = 'cc_site_options_translation_group';

    /**
     * Get the dynamic schema for site options
     */
    public function get_schema(): array
    {
        $schema = get_option(self::SCHEMA_OPTION);
        if (is_array($schema) && !empty($schema)) {
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
                        'label' => [
                            'de' => __('Company Name', 'content-core'),
                            'en' => 'Company Name',
                            'fr' => 'Nom de l\'entreprise'
                        ],
                        'type' => 'text',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                    'contact_person' => [
                        'label' => [
                            'de' => __('Contact Person', 'content-core'),
                            'en' => 'Contact Person',
                            'fr' => 'Personne de contact'
                        ],
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
                        'label' => [
                            'de' => __('Email Address', 'content-core'),
                            'en' => 'Email Address',
                            'fr' => 'Adresse e-mail'
                        ],
                        'type' => 'email',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                    'phone' => [
                        'label' => [
                            'de' => __('Phone Number', 'content-core'),
                            'en' => 'Phone Number',
                            'fr' => 'Numéro de téléphone'
                        ],
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
                        'label' => [
                            'de' => __('Street & Number', 'content-core'),
                            'en' => 'Street & Number',
                            'fr' => 'Rue et numéro'
                        ],
                        'type' => 'text',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                    'zip' => [
                        'label' => [
                            'de' => __('ZIP Code', 'content-core'),
                            'en' => 'ZIP Code',
                            'fr' => 'Code postal'
                        ],
                        'type' => 'text',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                    'city' => [
                        'label' => [
                            'de' => __('City', 'content-core'),
                            'en' => 'City',
                            'fr' => 'Ville'
                        ],
                        'type' => 'text',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                    'country' => [
                        'label' => [
                            'de' => __('Country', 'content-core'),
                            'en' => 'Country',
                            'fr' => 'Pays'
                        ],
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
                        'label' => [
                            'de' => __('Instagram URL', 'content-core'),
                            'en' => 'Instagram URL',
                            'fr' => 'URL Instagram'
                        ],
                        'type' => 'url',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                    'facebook_url' => [
                        'label' => [
                            'de' => __('Facebook URL', 'content-core'),
                            'en' => 'Facebook URL',
                            'fr' => 'URL Facebook'
                        ],
                        'type' => 'url',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                    'linkedin_url' => [
                        'label' => [
                            'de' => __('LinkedIn URL', 'content-core'),
                            'en' => 'LinkedIn URL',
                            'fr' => 'URL LinkedIn'
                        ],
                        'type' => 'url',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                ]
            ],
            'footer' => [
                'title' => __('Footer Content', 'content-core'),
                'fields' => [
                    'footer_text' => [
                        'label' => [
                            'de' => __('Footer Text', 'content-core'),
                            'en' => 'Footer Text',
                            'fr' => 'Texte du pied de page'
                        ],
                        'type' => 'textarea',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                    'logo_id' => [
                        'label' => [
                            'de' => __('Site Logo', 'content-core'),
                            'en' => 'Site Logo',
                            'fr' => 'Logo du site'
                        ],
                        'type' => 'image',
                        'default' => '',
                        'client_visible' => true,
                        'client_editable' => true
                    ],
                ]
            ],
        ];
    }

    /**
     * Get the schema with labels translated into the requested language
     */
    public function get_localized_schema(string $lang): array
    {
        $schema = $this->get_schema();
        foreach ($schema as &$section) {
            foreach ($section['fields'] as &$field) {
                if (is_array($field['label'])) {
                    $field['label'] = $field['label'][$lang] ?? reset($field['label']);
                }
            }
        }
        return $schema;
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

    /**
     * Get or generate a stable Translation Group ID for Site Options.
     */
    public function get_translation_group_id(): string
    {
        $group_id = get_option(self::GROUP_ID_OPTION);
        if (!$group_id) {
            $group_id = wp_generate_uuid4();
            update_option(self::GROUP_ID_OPTION, $group_id);
        }
        return (string) $group_id;
    }

    /**
     * Duplicate options from one language to another.
     */
    public function duplicate_options(string $source_lang, string $target_lang): void
    {
        $source_options = $this->get_options($source_lang);
        if (!empty($source_options)) {
            update_option("cc_site_options_{$target_lang}", $source_options);
        }
    }

    /**
     * Get site options for a specific language
     */
    public function get_options(string $lang): array
    {
        $option_key = "cc_site_options_{$lang}";
        $options = get_option($option_key, []);
        return is_array($options) ? $options : [];
    }
}