<?php
/**
 * Plugin Name:       Secure Image Sequence CAPTCHA
 * Plugin URI:        https://example.com/plugins/secure-image-sequence-captcha/
 * Description:       Protege formularios de Comentarios, Login y Registro con un CAPTCHA seguro basado en secuencias de imágenes.
 * Version:           1.4.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Soyunomas
 * Author URI:        https://example.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       secure-image-sequence-captcha
 * Domain Path:       /languages
 */

// --- 1. Comprobación de Seguridad Esencial ---
if (!defined("ABSPATH")) {
    die("¡Acceso no autorizado!");
}

// --- 2. Definición de Constantes del Plugin ---
define("SISC_VERSION", "1.4.1"); // Versión incrementada
define("SISC_PLUGIN_DIR", plugin_dir_path(__FILE__));
define("SISC_PLUGIN_URL", plugin_dir_url(__FILE__));
define("SISC_PLUGIN_BASENAME", plugin_basename(__FILE__));
define("SISC_OPTIONS_NAME", "sisc_options");
define("SISC_SETTINGS_SLUG", "sisc-settings");
define("SISC_TAXONOMY_SLUG", "sisc_captcha_category");
define("SISC_IMAGES_IN_SEQUENCE", 3);
define("SISC_DISTRACTOR_IMAGES", 3);
define("SISC_TOTAL_IMAGES", SISC_IMAGES_IN_SEQUENCE + SISC_DISTRACTOR_IMAGES);
define("SISC_TRANSIENT_EXPIRATION", 5 * MINUTE_IN_SECONDS);
define("SISC_ERROR_TRANSIENT_EXPIRATION", 60);
define("SISC_NONCE_ACTION", "sisc-validate-captcha");
define("SISC_IMAGE_SIZE", "thumbnail");
define("SISC_JS_HANDLE", "sisc-captcha-script");
define("SISC_PREDEFINED_IMAGES_DIR", SISC_PLUGIN_DIR . "images/");
define("SISC_PREDEFINED_IMAGES_URL", SISC_PLUGIN_URL . "images/");
define("SISC_MAX_IMAGE_DIMENSION", 75);

// --- 3. Clase Principal del Plugin ---
if (!class_exists("Secure_Image_Sequence_Captcha")) {
    class Secure_Image_Sequence_Captcha
    {
        private static $instance = null;
        private $options = null;
        private $captcha_rendered_on_page = false;

        private function __construct()
        {
            $this->options = get_option(
                SISC_OPTIONS_NAME,
                $this->get_default_options()
            );
            add_action("init", [$this, "load_textdomain"]);
            add_action("init", [$this, "register_custom_taxonomy"]);

            if (is_admin()) {
                add_action("admin_menu", [$this, "register_admin_menu"]);
                add_action("admin_init", [$this, "register_settings"]);
                add_filter("plugin_action_links_" . SISC_PLUGIN_BASENAME, [
                    $this,
                    "add_settings_link",
                ]);
                add_action("admin_notices", [$this, "display_admin_notices"]);

                add_filter("manage_edit-" . SISC_TAXONOMY_SLUG . "_columns", [
                    $this,
                    "modify_taxonomy_columns",
                ]);
                add_filter(
                    "manage_" . SISC_TAXONOMY_SLUG . "_custom_column",
                    [$this, "render_custom_taxonomy_column"],
                    10,
                    3
                );
            }

            $this->setup_captcha_hooks();
            add_action("wp_enqueue_scripts", [
                $this,
                "enqueue_frontend_assets",
            ]);
            add_action("login_enqueue_scripts", [
                $this,
                "enqueue_login_assets",
            ]);
            add_action("template_redirect", [
                $this,
                "maybe_display_comment_captcha_error",
            ]);
        }

        private function setup_captcha_hooks()
        {
            if (!empty($this->options["enable_comments"])) {
                if (!is_admin()) {
                    add_action("comment_form_after_fields", [
                        $this,
                        "display_captcha_in_comments",
                    ]);
                    add_action("comment_form_logged_in_after", [
                        $this,
                        "display_captcha_in_comments",
                    ]);
                    add_action("comment_form_before", [
                        $this,
                        "display_transient_comment_error",
                    ]);
                }
                add_filter(
                    "preprocess_comment",
                    [$this, "validate_comment_captcha"],
                    10
                );
            }
            if (!empty($this->options["enable_login"])) {
                add_action("login_form", [$this, "display_captcha_in_login"]);
                add_filter(
                    "authenticate",
                    [$this, "validate_login_captcha"],
                    30,
                    3
                );
            }
            if (!empty($this->options["enable_register"])) {
                add_action("register_form", [
                    $this,
                    "display_captcha_in_register",
                ]);
                add_filter(
                    "registration_errors",
                    [$this, "validate_register_captcha"],
                    10,
                    3
                );
            }
        }
        public static function get_instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        private function get_default_options()
        {
            return [
                "enable_login" => 0,
                "enable_register" => 0,
                "enable_comments" => 0,
                "image_source" => "custom",
            ];
        }
        public function load_textdomain()
        {
            load_plugin_textdomain(
                "secure-image-sequence-captcha",
                false,
                dirname(SISC_PLUGIN_BASENAME) . "/languages"
            );
        }
        public function register_custom_taxonomy()
        {
            $labels = [
                "name" => _x(
                    "CAPTCHA Categories",
                    "taxonomy general name",
                    "secure-image-sequence-captcha"
                ),
                "singular_name" => _x(
                    "CAPTCHA Category",
                    "taxonomy singular name",
                    "secure-image-sequence-captcha"
                ),
                "search_items" => __(
                    "Search CAPTCHA Categories",
                    "secure-image-sequence-captcha"
                ),
                "all_items" => __(
                    "All CAPTCHA Categories",
                    "secure-image-sequence-captcha"
                ),
                "parent_item" => __(
                    "Parent CAPTCHA Category",
                    "secure-image-sequence-captcha"
                ),
                "parent_item_colon" => __(
                    "Parent CAPTCHA Category:",
                    "secure-image-sequence-captcha"
                ),
                "edit_item" => __(
                    "Edit CAPTCHA Category",
                    "secure-image-sequence-captcha"
                ),
                "update_item" => __(
                    "Update CAPTCHA Category",
                    "secure-image-sequence-captcha"
                ),
                "add_new_item" => __(
                    "Add New CAPTCHA Category",
                    "secure-image-sequence-captcha"
                ),
                "new_item_name" => __(
                    "New CAPTCHA Category Name",
                    "secure-image-sequence-captcha"
                ),
                "menu_name" => __(
                    "CAPTCHA Categories",
                    "secure-image-sequence-captcha"
                ),
                "not_found" => __(
                    "No CAPTCHA categories found.",
                    "secure-image-sequence-captcha"
                ),
                "no_terms" => __(
                    "No CAPTCHA categories",
                    "secure-image-sequence-captcha"
                ),
                "items_list_navigation" => __(
                    "CAPTCHA categories list navigation",
                    "secure-image-sequence-captcha"
                ),
                "items_list" => __(
                    "CAPTCHA categories list",
                    "secure-image-sequence-captcha"
                ),
            ];
            $args = [
                "labels" => $labels,
                "hierarchical" => true,
                "public" => false,
                "show_ui" => true,
                "show_admin_column" => true,
                "query_var" => false,
                "rewrite" => false,
                "show_in_rest" => true,
                "description" => __(
                    "Organize images for the Custom Image Sequence CAPTCHA. Each category requires a minimum of 6 images to be functional.",
                    "secure-image-sequence-captcha"
                ),
            ];
            register_taxonomy(SISC_TAXONOMY_SLUG, ["attachment"], $args);
        }

        // --- Métodos de Avisos Administrativos ---

        /**
         * Muestra avisos administrativos contextuales en las páginas del plugin.
         */
        public function display_admin_notices()
        {
            $screen = get_current_screen();
            if (!$screen) {
                return;
            }

            // Aviso en la página de gestión de la taxonomía
            if ("edit-" . SISC_TAXONOMY_SLUG === $screen->id) { ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php esc_html_e(
                            "Quick Tip:",
                            "secure-image-sequence-captcha"
                        ); ?></strong>
                        <?php echo wp_kses_post(
                            sprintf(
                                __(
                                    "For a category to be used by the CAPTCHA, it must contain at least %d images.",
                                    "secure-image-sequence-captcha"
                                ),
                                SISC_TOTAL_IMAGES
                            )
                        ); ?>
                    </p>
                </div>
                <?php }

            // Avisos en la página de ajustes del plugin
            if (
                "settings_page_" . SISC_SETTINGS_SLUG === $screen->id &&
                "custom" === $this->options["image_source"]
            ) {
                $status = $this->get_custom_category_status();

                if ($status["total"] > 0 && empty($status["valid"])) {
                    // Error: Hay categorías, pero ninguna es válida.
                    $taxonomy_url = admin_url(
                        "edit-tags.php?taxonomy=" .
                            SISC_TAXONOMY_SLUG .
                            "&post_type=attachment"
                    ); ?>
                    <div class="notice notice-error is-dismissible">
                        <p>
                            <strong><?php esc_html_e(
                                "Action Required:",
                                "secure-image-sequence-captcha"
                            ); ?></strong>
                            <?php echo wp_kses_post(
                                sprintf(
                                    __(
                                        'The Image Sequence CAPTCHA is currently inactive because none of your CAPTCHA Categories have the required minimum of %1$d images. Please <a href="%2$s">update your categories</a> to activate protection.',
                                        "secure-image-sequence-captcha"
                                    ),
                                    SISC_TOTAL_IMAGES,
                                    esc_url($taxonomy_url)
                                )
                            ); ?>
                        </p>
                    </div>
                    <?php
                } elseif (!empty($status["invalid"])) {
                    // Advertencia: algunas categorías no son válidas.
                    $invalid_names = wp_list_pluck(
                        $status["invalid"],
                        "name"
                    ); ?>
                    <div class="notice notice-warning is-dismissible">
                        <p>
                            <strong><?php esc_html_e(
                                "Warning:",
                                "secure-image-sequence-captcha"
                            ); ?></strong>
                            <?php esc_html_e(
                                "The following CAPTCHA categories have fewer than the required minimum of images and will be ignored:",
                                "secure-image-sequence-captcha"
                            ); ?>
                            <ul style="list-style: disc; margin-left: 20px; margin-top: 5px; margin-bottom: 5px;">
                                <?php foreach ($invalid_names as $name): ?>
                                    <li><?php echo esc_html($name); ?></li>
                                <?php endforeach; ?>
                            </ul>
                             <?php esc_html_e(
                                 "The CAPTCHA will continue to work using only the valid categories.",
                                 "secure-image-sequence-captcha"
                             ); ?>
                        </p>
                    </div>
                    <?php
                }
            }
        }

        /**
         * Obtiene el estado de las categorías de imágenes personalizadas.
         *
         * @return array Un array con el estado de las categorías.
         */
        private function get_custom_category_status()
        {
            $all_terms = get_terms([
                "taxonomy" => SISC_TAXONOMY_SLUG,
                "hide_empty" => false,
            ]);
            if (empty($all_terms) || is_wp_error($all_terms)) {
                return ["total" => 0, "valid" => [], "invalid" => []];
            }

            $valid_categories = [];
            $invalid_categories = [];
            foreach ($all_terms as $term) {
                $query = new WP_Query([
                    "post_type" => "attachment",
                    "post_status" => "inherit",
                    "posts_per_page" => 1,
                    "tax_query" => [
                        [
                            "taxonomy" => SISC_TAXONOMY_SLUG,
                            "field" => "term_id",
                            "terms" => $term->term_id,
                        ],
                    ],
                    "fields" => "ids",
                    "no_found_rows" => false,
                ]);
                if ($query->found_posts >= SISC_TOTAL_IMAGES) {
                    $valid_categories[] = $term;
                } else {
                    $invalid_categories[] = $term;
                }
            }
            return [
                "total" => count($all_terms),
                "valid" => $valid_categories,
                "invalid" => $invalid_categories,
            ];
        }

        // --- Métodos Página de Ajustes ---
        public function register_admin_menu()
        {
            add_options_page(
                __(
                    "Secure Image Sequence CAPTCHA Settings",
                    "secure-image-sequence-captcha"
                ),
                __("Image Sequence CAPTCHA", "secure-image-sequence-captcha"),
                "manage_options",
                SISC_SETTINGS_SLUG,
                [$this, "render_settings_page"]
            );
        }
        public function register_settings()
        {
            register_setting(SISC_OPTIONS_NAME, SISC_OPTIONS_NAME, [
                $this,
                "sanitize_options",
            ]);
            add_settings_section(
                "sisc_section_activation",
                __("Enable CAPTCHA on Forms", "secure-image-sequence-captcha"),
                [$this, "render_section_activation_cb"],
                SISC_SETTINGS_SLUG
            );
            add_settings_field(
                "sisc_field_enable_comments",
                __("Comments Form", "secure-image-sequence-captcha"),
                [$this, "render_field_checkbox_cb"],
                SISC_SETTINGS_SLUG,
                "sisc_section_activation",
                [
                    "label_for" => "sisc_enable_comments",
                    "option_name" => "enable_comments",
                    "description" => __(
                        "Protects the standard WordPress comment form.",
                        "secure-image-sequence-captcha"
                    ),
                ]
            );
            add_settings_field(
                "sisc_field_enable_login",
                __("Login Form", "secure-image-sequence-captcha"),
                [$this, "render_field_checkbox_cb"],
                SISC_SETTINGS_SLUG,
                "sisc_section_activation",
                [
                    "label_for" => "sisc_enable_login",
                    "option_name" => "enable_login",
                    "description" => __(
                        "Protects the wp-login.php form.",
                        "secure-image-sequence-captcha"
                    ),
                ]
            );
            add_settings_field(
                "sisc_field_enable_register",
                __("Registration Form", "secure-image-sequence-captcha"),
                [$this, "render_field_checkbox_cb"],
                SISC_SETTINGS_SLUG,
                "sisc_section_activation",
                [
                    "label_for" => "sisc_enable_register",
                    "option_name" => "enable_register",
                    "description" => __(
                        "Protects the standard WordPress registration form.",
                        "secure-image-sequence-captcha"
                    ),
                ]
            );
            add_settings_section(
                "sisc_section_image_source",
                __(
                    "Image Source Configuration",
                    "secure-image-sequence-captcha"
                ),
                [$this, "render_section_image_source_cb"],
                SISC_SETTINGS_SLUG
            );
            add_settings_field(
                "sisc_field_image_source",
                __("Select Image Source", "secure-image-sequence-captcha"),
                [$this, "render_field_image_source_cb"],
                SISC_SETTINGS_SLUG,
                "sisc_section_image_source",
                ["label_for" => "sisc_image_source"]
            );
        }
        public function render_section_activation_cb($args)
        {
            echo '<p id="' .
                esc_attr($args["id"]) .
                '-description">' .
                esc_html__(
                    "Select the forms where you want to enable the Image Sequence CAPTCHA.",
                    "secure-image-sequence-captcha"
                ) .
                "</p>";
        }
        public function render_section_image_source_cb($args)
        {
            echo '<p id="' .
                esc_attr($args["id"]) .
                '-description">' .
                esc_html__(
                    "Choose how the CAPTCHA images are sourced.",
                    "secure-image-sequence-captcha"
                ) .
                "</p>";
        }
        public function render_field_checkbox_cb($args)
        {
            $option_name = $args["option_name"];
            $label_for = $args["label_for"];
            $option_key = SISC_OPTIONS_NAME;
            $current_value = isset($this->options[$option_name])
                ? $this->options[$option_name]
                : 0;
            $description = isset($args["description"])
                ? $args["description"]
                : "";
            echo '<input type="checkbox" id="' .
                esc_attr($label_for) .
                '" name="' .
                esc_attr($option_key . "[" . $option_name . "]") .
                '" value="1" ' .
                checked($current_value, 1, false) .
                " />";
            echo ' <label for="' .
                esc_attr($label_for) .
                '"> ' .
                esc_html__("Enable", "secure-image-sequence-captcha") .
                "</label>";
            if (!empty($description)) {
                echo '<p class="description">' .
                    wp_kses_post($description) .
                    "</p>";
            }
        }
        public function render_field_image_source_cb($args)
        {
            $option_key = SISC_OPTIONS_NAME;
            $current_value = isset($this->options["image_source"])
                ? $this->options["image_source"]
                : "custom";
            $id_base = $args["label_for"];
            $sources = [
                "custom" => __(
                    "Custom Images (Media Library & CAPTCHA Categories)",
                    "secure-image-sequence-captcha"
                ),
                "predefined" => __(
                    "Predefined Image Sets (Included with Plugin)",
                    "secure-image-sequence-captcha"
                ),
            ];
            echo '<fieldset><legend class="screen-reader-text"><span>' .
                esc_html__(
                    "Select Image Source",
                    "secure-image-sequence-captcha"
                ) .
                "</span></legend>";
            foreach ($sources as $value => $label) {
                $checked = checked($current_value, $value, false);
                $id = esc_attr($id_base . "_" . $value);
                echo '<label for="' .
                    $id .
                    '"><input type="radio" id="' .
                    $id .
                    '" name="' .
                    esc_attr($option_key . "[image_source]") .
                    '" value="' .
                    esc_attr($value) .
                    '" ' .
                    $checked .
                    " /> " .
                    esc_html($label) .
                    "</label><br />";
            }
            echo '<p class="description" style="margin-left: 20px; margin-top: 5px;">';
            echo "<strong>" .
                esc_html__("Custom Images:", "secure-image-sequence-captcha") .
                "</strong> " .
                esc_html__(
                    'Requires you to upload images to the Media Library and assign them to "CAPTCHA Categories".',
                    "secure-image-sequence-captcha"
                ) .
                "<br>";
            echo "<strong>" .
                esc_html__(
                    "Predefined Sets:",
                    "secure-image-sequence-captcha"
                ) .
                "</strong> " .
                esc_html__(
                    'Uses built-in image sets (like fruits, animals) in the plugin\'s `images` folder. Easy setup.',
                    "secure-image-sequence-captcha"
                );
            echo "</p></fieldset>";
            if ("custom" === $current_value) {
                $taxonomy_url = admin_url(
                    "edit-tags.php?taxonomy=" .
                        SISC_TAXONOMY_SLUG .
                        "&post_type=attachment"
                );
                echo '<p style="margin-top:10px;">' .
                    sprintf(
                        wp_kses(
                            __(
                                'Manage your <a href="%s">CAPTCHA Categories here</a>.',
                                "secure-image-sequence-captcha"
                            ),
                            ["a" => ["href" => []]]
                        ),
                        esc_url($taxonomy_url)
                    ) .
                    "</p>";
            }
        }
        public function render_settings_page()
        {
            if (!current_user_can("manage_options")) {
                wp_die(
                    esc_html__(
                        "You do not have sufficient permissions to access this page.",
                        "secure-image-sequence-captcha"
                    )
                );
            }
            echo '<div class="wrap"><h1>' .
                esc_html(get_admin_page_title()) .
                '</h1><form action="options.php" method="post">';
            settings_fields(SISC_OPTIONS_NAME);
            do_settings_sections(SISC_SETTINGS_SLUG);
            submit_button(__("Save Settings", "secure-image-sequence-captcha"));
            echo "</form></div>";
        }
        public function sanitize_options($input)
        {
            $sanitized_input = [];
            $defaults = $this->get_default_options();
            $sanitized_input["enable_login"] =
                isset($input["enable_login"]) && $input["enable_login"] == "1"
                    ? 1
                    : 0;
            $sanitized_input["enable_register"] =
                isset($input["enable_register"]) &&
                $input["enable_register"] == "1"
                    ? 1
                    : 0;
            $sanitized_input["enable_comments"] =
                isset($input["enable_comments"]) &&
                $input["enable_comments"] == "1"
                    ? 1
                    : 0;
            $sanitized_input["image_source"] =
                isset($input["image_source"]) &&
                in_array($input["image_source"], ["custom", "predefined"])
                    ? $input["image_source"]
                    : $defaults["image_source"];
            $this->options = $sanitized_input;
            return $sanitized_input;
        }
        public function add_settings_link($links)
        {
            $settings_link = sprintf(
                '<a href="%s">%s</a>',
                esc_url(
                    admin_url("options-general.php?page=" . SISC_SETTINGS_SLUG)
                ),
                esc_html__("Settings", "secure-image-sequence-captcha")
            );
            array_unshift($links, $settings_link);
            return $links;
        }

        // --- Encolado de Assets (JS y CSS) ---
        private function should_enqueue_assets()
        {
            if (
                !empty($this->options["enable_comments"]) &&
                (is_single() || is_page())
            ) {
                return true;
            }
            global $pagenow;
            if ($pagenow === "wp-login.php") {
                return true;
            }
            return false;
        }
        public function enqueue_frontend_assets()
        {
            if ($this->should_enqueue_assets() && !is_admin()) {
                $this->enqueue_common_assets();
            }
        }
        public function enqueue_login_assets()
        {
            if (
                !empty($this->options["enable_login"]) ||
                !empty($this->options["enable_register"])
            ) {
                $this->enqueue_common_assets();
            }
        }
        private function enqueue_common_assets()
        {
            wp_enqueue_style(
                "sisc-captcha-style",
                SISC_PLUGIN_URL . "assets/css/sisc-captcha.css",
                [],
                SISC_VERSION
            );
            $max_dimension = SISC_MAX_IMAGE_DIMENSION;
            $inline_styles = ".sisc-image-selection-area{min-height:calc({$max_dimension}px + 6px);}.sisc-captcha-image{max-width:{$max_dimension}px;max-height:{$max_dimension}px;}";
            wp_add_inline_style("sisc-captcha-style", $inline_styles);
            wp_enqueue_script(
                SISC_JS_HANDLE,
                SISC_PLUGIN_URL . "assets/js/sisc-captcha.js",
                ["jquery"],
                SISC_VERSION,
                true
            );
        }

        // --- Métodos CAPTCHA: Generación y Renderizado ---
        private function generate_captcha_challenge()
        {
            $image_source = isset($this->options['image_source']) ? $this->options['image_source'] : 'custom';
            
            $image_details = false;

            if ('custom' === $image_source) {
                $status = $this->get_custom_category_status();
                
                if (!empty($status['valid'])) {
                    $valid_category_ids = wp_list_pluck($status['valid'], 'term_id');
                    $random_term_id = $valid_category_ids[array_rand($valid_category_ids)];
                    
                    $query_args = [
                        'post_type'      => 'attachment',
                        'post_status'    => 'inherit',
                        'posts_per_page' => 50,
                        'tax_query'      => [[
                            'taxonomy' => SISC_TAXONOMY_SLUG,
                            'field'    => 'term_id',
                            'terms'    => $random_term_id,
                        ]],
                        'fields'         => 'ids',
                        'orderby'        => 'rand',
                    ];
                    $image_query = new WP_Query($query_args);
                    $all_image_ids = $image_query->posts;
                    wp_reset_postdata();

                    $selected_image_ids = array_slice($all_image_ids, 0, SISC_TOTAL_IMAGES);
                    
                    if (count($selected_image_ids) === SISC_TOTAL_IMAGES) {
                        $image_details = [
                            'source_type'         => 'custom',
                            'source_id'           => $random_term_id,
                            'selected_identifiers'=> $selected_image_ids,
                            'correct_identifiers' => array_slice($selected_image_ids, 0, SISC_IMAGES_IN_SEQUENCE),
                        ];
                    }
                } else {
                    error_log('[SISC] Custom source failed: No valid categories found. Attempting to fall back to predefined sets.');
                }
            }
            
            if (false === $image_details) {
                $image_details = $this->_get_predefined_challenge_data();
            }

            if (false === $image_details) {
                error_log('[SISC] FATAL: CAPTCHA generation failed. No valid image sources available (custom or predefined).');
                return false;
            }

            $challenge_images = [];
            $temporal_id_map = [];
            $correct_temporal_sequence_map = [];

            foreach ($image_details['selected_identifiers'] as $identifier) {
                $temporal_id = bin2hex(random_bytes(8));
                
                if ('predefined' === $image_details['source_type']) {
                    $filename = basename($identifier);
                    $image_url = SISC_PREDEFINED_IMAGES_URL . $image_details['source_id'] . '/' . $filename;
                    $alt_text = ucfirst(str_replace(['-', '_'], ' ', pathinfo($filename, PATHINFO_FILENAME)));
                    $temporal_id_map[$temporal_id] = $filename;
                } else {
                    $image_url = wp_get_attachment_image_url($identifier, SISC_IMAGE_SIZE);
                    $alt_text = get_post_meta($identifier, '_wp_attachment_image_alt', true) ?: get_the_title($identifier);
                    $temporal_id_map[$temporal_id] = $identifier;
                }
                
                if (!$image_url) { continue; }

                $challenge_images[] = [
                    'temp_id' => $temporal_id,
                    'url'     => $image_url,
                    'alt'     => $alt_text ?: __('CAPTCHA Image', 'secure-image-sequence-captcha'),
                ];
                
                $correct_pos = array_search($identifier, $image_details['correct_identifiers']);
                if ($correct_pos !== false) {
                    $correct_temporal_sequence_map[$correct_pos] = $temporal_id;
                }
            }

            if ('predefined' === $image_details['source_type']) {
                $correct_image_titles = array_map(function ($filepath) {
                    return ucfirst(str_replace(['-', '_'], ' ', pathinfo(basename($filepath), PATHINFO_FILENAME)));
                }, $image_details['correct_identifiers']);
            } else {
                $correct_image_titles = array_map('get_the_title', $image_details['correct_identifiers']);
            }

            ksort($correct_temporal_sequence_map);
            $correct_temporal_sequence = array_values($correct_temporal_sequence_map);

            if (count($challenge_images) !== SISC_TOTAL_IMAGES || count($correct_temporal_sequence) !== SISC_IMAGES_IN_SEQUENCE) {
                error_log('[SISC] Final consistency check failed. Aborting CAPTCHA generation.');
                return false;
            }
            
            $valid_titles = array_filter($correct_image_titles);
            if (count($valid_titles) !== SISC_IMAGES_IN_SEQUENCE) {
                $question = __('Click the images in the correct sequence.', 'secure-image-sequence-captcha');
            } else {
                $question = sprintf(
                    __('Click the images in this order: %s', 'secure-image-sequence-captcha'),
                    implode(', ', $valid_titles)
                );
            }

            $transient_data = [
                'correct_sequence' => $correct_temporal_sequence,
                'temporal_map'     => $temporal_id_map,
                'timestamp'        => time(),
                'source_type'      => $image_details['source_type'],
                'source_id'        => $image_details['source_id'],
            ];

            $transient_key = 'sisc_ch_' . bin2hex(random_bytes(12));
            set_transient($transient_key, $transient_data, SISC_TRANSIENT_EXPIRATION);
            
            $nonce = wp_create_nonce(SISC_NONCE_ACTION . '_' . $transient_key);
            shuffle($challenge_images);
            
            return [
                'question'      => $question,
                'images'        => $challenge_images,
                'nonce'         => $nonce,
                'transient_key' => $transient_key,
            ];
        }

        private function _get_predefined_challenge_data() {
            if (!is_dir(SISC_PREDEFINED_IMAGES_DIR) || !is_readable(SISC_PREDEFINED_IMAGES_DIR)) {
                error_log('[SISC] Predefined directory error: ' . SISC_PREDEFINED_IMAGES_DIR);
                return false;
            }

            $available_sets_paths = [];
            $all_items = scandir(SISC_PREDEFINED_IMAGES_DIR);
            
            if (false === $all_items) {
                error_log('[SISC] Scandir error on: ' . SISC_PREDEFINED_IMAGES_DIR);
                return false;
            }

            foreach ($all_items as $item) {
                if ($item === '.' || $item === '..' || strpos($item, '.') === 0) {
                    continue;
                }
                $potential_set_path = SISC_PREDEFINED_IMAGES_DIR . $item;
                if (is_dir($potential_set_path) && is_readable($potential_set_path)) {
                    $image_files_in_set = glob($potential_set_path . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
                    if (!empty($image_files_in_set) && count($image_files_in_set) >= SISC_TOTAL_IMAGES) {
                        $available_sets_paths[] = $potential_set_path;
                    }
                }
            }

            if (empty($available_sets_paths)) {
                error_log('[SISC] Predefined source failed: No valid sets with enough images found.');
                return false;
            }

            $random_set_path = $available_sets_paths[array_rand($available_sets_paths)];
            $set_name = basename($random_set_path);
            $image_files = glob($random_set_path . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
            
            if (empty($image_files)) {
                 error_log('[SISC] Glob error in predefined set: ' . $random_set_path);
                return false;
            }

            shuffle($image_files);
            $selected_image_files = array_slice($image_files, 0, SISC_TOTAL_IMAGES);

            return [
                'source_type'         => 'predefined',
                'source_id'           => $set_name,
                'selected_identifiers'=> $selected_image_files,
                'correct_identifiers' => array_slice($selected_image_files, 0, SISC_IMAGES_IN_SEQUENCE),
            ];
        }
        private function render_captcha_html(
            $challenge_data,
            $context = "comments"
        ) {
            if (
                empty($challenge_data) ||
                !is_array($challenge_data) ||
                empty($challenge_data["images"]) ||
                empty($challenge_data["transient_key"])
            ) {
                error_log(
                    "[SISC] Render failed: Invalid data for context '{$context}'."
                );
                echo '<p class="sisc-error"><em>' .
                    esc_html__(
                        "Error rendering CAPTCHA.",
                        "secure-image-sequence-captcha"
                    ) .
                    "</em></p>";
                return;
            }
            $question = $challenge_data["question"];
            $images = $challenge_data["images"];
            $nonce = $challenge_data["nonce"];
            $transient_key = $challenge_data["transient_key"];
            $container_id = "sisc-captcha-" . esc_attr($transient_key);
            $input_id = "sisc-user-sequence-" . esc_attr($transient_key);
            $question_html =
                '<p class="sisc-question" id="' .
                $container_id .
                '-question">' .
                esc_html($question) .
                "</p>";
            if ($context === "login" || $context === "register") {
                $question_html =
                    '<label for="' .
                    $input_id .
                    '" class="sisc-question" id="' .
                    $container_id .
                    '-question">' .
                    esc_html($question) .
                    "</label>";
            }
            ?> <div class="sisc-captcha-container sisc-context-<?php echo esc_attr(
     $context
 ); ?>" id="<?php echo $container_id; ?>"> <?php echo $question_html; ?> <div class="sisc-image-selection-area" role="group" aria-labelledby="<?php echo $container_id; ?>-question"> <?php foreach (
    $images
    as $image
): ?> <?php
 $img_url = !empty($image["url"]) ? esc_url($image["url"]) : "";
 $img_alt = !empty($image["alt"]) ? esc_attr($image["alt"]) : "";
 $temp_id = !empty($image["temp_id"]) ? esc_attr($image["temp_id"]) : "";
 $aria_label = sprintf(
     __("Select image: %s", "secure-image-sequence-captcha"),
     $img_alt
 );
 if (
     $img_url &&
     $temp_id
 ): ?> <img src="<?php echo $img_url; ?>" alt="<?php echo $img_alt; ?>" data-sisc-id="<?php echo $temp_id; ?>" class="sisc-captcha-image" role="button" tabindex="0" aria-label="<?php echo esc_attr(
    $aria_label
); ?>" /> <?php endif;
 ?> <?php endforeach; ?> </div> <input type="hidden" name="sisc_nonce" value="<?php echo esc_attr(
     $nonce
 ); ?>"> <input type="hidden" name="sisc_transient_key" value="<?php echo esc_attr(
    $transient_key
); ?>"> <input type="hidden" name="sisc_user_sequence" id="<?php echo $input_id; ?>" value="" autocomplete="off"> <noscript><p class="sisc-error-js"><?php esc_html_e(
    "JavaScript is required to solve the CAPTCHA.",
    "secure-image-sequence-captcha"
); ?></p></noscript> </div> <?php $this->captcha_rendered_on_page = true;
        }

        // --- Métodos CAPTCHA: Display específico por formulario ---
        public function display_captcha_in_comments()
        {
            $challenge_data = $this->generate_captcha_challenge();
            if (!$challenge_data) {
                echo '<p class="sisc-error"><em>' .
                    esc_html__(
                        "CAPTCHA could not be generated due to a configuration issue. Submission is blocked.",
                        "secure-image-sequence-captcha"
                    ) .
                    "</em></p>";
                echo '<input type="hidden" name="sisc_transient_key" value="sisc_generation_failed">';
                return;
            }
            $this->render_captcha_html($challenge_data, "comments");
        }

        public function display_captcha_in_login()
        {
            $challenge_data = $this->generate_captcha_challenge();
            if (!$challenge_data) {
                echo '<p class="sisc-error login-error"><em>' .
                    esc_html__(
                        "CAPTCHA generation failed due to a configuration issue. Submission is blocked.",
                        "secure-image-sequence-captcha"
                    ) .
                    "</em></p>";
                echo '<input type="hidden" name="sisc_transient_key" value="sisc_generation_failed">';
                return;
            }
            echo '<div style="margin-bottom: 15px;">';
            $this->render_captcha_html($challenge_data, "login");
            echo "</div>";
        }

        public function display_captcha_in_register()
        {
            $challenge_data = $this->generate_captcha_challenge();
            if (!$challenge_data) {
                echo '<p class="sisc-error register-error"><em>' .
                    esc_html__(
                        "CAPTCHA generation failed due to a configuration issue. Submission is blocked.",
                        "secure-image-sequence-captcha"
                    ) .
                    "</em></p>";
                echo '<input type="hidden" name="sisc_transient_key" value="sisc_generation_failed">';
                return;
            }
            echo '<div style="margin-bottom: 15px;">';
            $this->render_captcha_html($challenge_data, "register");
            echo "</div>";
        }

        // --- Métodos CAPTCHA: Validación ---
        private function perform_captcha_validation()
        {
            if (
                !isset(
                    $_POST["sisc_nonce"],
                    $_POST["sisc_transient_key"],
                    $_POST["sisc_user_sequence"]
                )
            ) {
                return new WP_Error(
                    "sisc_missing_fields",
                    __(
                        "CAPTCHA validation failed: Missing required fields.",
                        "secure-image-sequence-captcha"
                    )
                );
            }
            $nonce = $_POST["sisc_nonce"];
            $transient_key = sanitize_key($_POST["sisc_transient_key"]);
            $user_sequence_raw = sanitize_text_field(
                wp_unslash($_POST["sisc_user_sequence"])
            );
            if (
                !preg_match(
                    '/^([a-f0-9]{16},)*[a-f0-9]{16}$|^$/',
                    $user_sequence_raw
                )
            ) {
                error_log(
                    "[SISC Validation] Invalid sequence format: " .
                        $user_sequence_raw
                );
                return new WP_Error(
                    "sisc_invalid_format",
                    __(
                        "CAPTCHA validation failed: Invalid sequence format.",
                        "secure-image-sequence-captcha"
                    )
                );
            }
            if (
                !wp_verify_nonce(
                    $nonce,
                    SISC_NONCE_ACTION . "_" . $transient_key
                )
            ) {
                error_log(
                    "[SISC Validation] Nonce failed for key: " . $transient_key
                );
                return new WP_Error(
                    "sisc_nonce_failure",
                    __(
                        "Security check failed (Nonce mismatch). Please try again.",
                        "secure-image-sequence-captcha"
                    )
                );
            }
            $transient_data = get_transient($transient_key);
            delete_transient($transient_key);
            if (
                false === $transient_data ||
                !is_array($transient_data) ||
                !isset($transient_data["correct_sequence"])
            ) {
                error_log(
                    "[SISC Validation] Transient invalid/expired for key: " .
                        $transient_key
                );
                return new WP_Error(
                    "sisc_transient_invalid",
                    __(
                        "CAPTCHA challenge has expired or is invalid. Please reload the page and try again.",
                        "secure-image-sequence-captcha"
                    )
                );
            }
            $correct_sequence = $transient_data["correct_sequence"];
            $user_sequence_array = !empty($user_sequence_raw)
                ? explode(",", $user_sequence_raw)
                : [];
            if ($user_sequence_array !== $correct_sequence) {
                return new WP_Error(
                    "sisc_incorrect_sequence",
                    __(
                        "Incorrect CAPTCHA sequence. Please try again.",
                        "secure-image-sequence-captcha"
                    )
                );
            }
            return true;
        }

        public function validate_comment_captcha($commentdata)
        {
            if (empty($this->options["enable_comments"])) {
                return $commentdata;
            }

            if (!isset($_POST["sisc_transient_key"])) {
                wp_die(
                    "<strong>" .
                        esc_html__("ERROR:", "secure-image-sequence-captcha") .
                        "</strong> " .
                        esc_html__(
                            "CAPTCHA validation failed because a required form field was missing. This may be due to a site configuration issue. Please go back and try again. If the problem persists, contact the site administrator.",
                            "secure-image-sequence-captcha"
                        ),
                    esc_html__(
                        "CAPTCHA Validation Error",
                        "secure-image-sequence-captcha"
                    ),
                    [
                        "response" => 403,
                        "back_link" => true,
                    ]
                );
            }

            $validation_result = $this->perform_captcha_validation();

            if (is_wp_error($validation_result)) {
                $error_code = $validation_result->get_error_code();
                $error_message = $validation_result->get_error_message();

                $transient_key =
                    "sisc_comm_err_" . md5(uniqid(wp_rand(), true));
                $comment_data_to_preserve = [
                    "comment_author" => isset($commentdata["comment_author"])
                        ? $commentdata["comment_author"]
                        : "",
                    "comment_author_email" => isset(
                        $commentdata["comment_author_email"]
                    )
                        ? $commentdata["comment_author_email"]
                        : "",
                    "comment_author_url" => isset(
                        $commentdata["comment_author_url"]
                    )
                        ? $commentdata["comment_author_url"]
                        : "",
                    "comment_content" => isset($commentdata["comment_content"])
                        ? $commentdata["comment_content"]
                        : "",
                ];

                set_transient(
                    $transient_key,
                    [
                        "error_code" => $error_code,
                        "error_message" => $error_message,
                        "comment_data" => $comment_data_to_preserve,
                    ],
                    SISC_ERROR_TRANSIENT_EXPIRATION
                );

                $redirect_url = isset($commentdata["comment_post_ID"])
                    ? get_permalink($commentdata["comment_post_ID"])
                    : wp_get_referer();
                if (!$redirect_url) {
                    $redirect_url = home_url("/");
                }

                $redirect_url = add_query_arg(
                    "sisc_error",
                    $transient_key,
                    $redirect_url
                );
                $redirect_url .= "#commentform";

                wp_safe_redirect($redirect_url);
                exit();
            }

            return $commentdata;
        }
        public function display_transient_comment_error()
        {
            if (isset($_GET["sisc_error"])) {
                $transient_key = sanitize_key($_GET["sisc_error"]);
                $error_data = get_transient($transient_key);
                if ($error_data && isset($error_data["error_message"])) {
                    echo '<div class="sisc-error comment-form-error"><strong>' .
                        esc_html__(
                            "CAPTCHA Error:",
                            "secure-image-sequence-captcha"
                        ) .
                        "</strong> " .
                        esc_html($error_data["error_message"]) .
                        "</div>";
                    delete_transient($transient_key);
                }
            }
        }

        public function validate_login_captcha($user, $username, $password)
        {
            if (empty($this->options["enable_login"])) {
                return $user;
            }

            $validation_result = $this->perform_captcha_validation();

            if (is_wp_error($validation_result)) {
                $wp_error = new WP_Error();
                $wp_error->add(
                    $validation_result->get_error_code(),
                    $validation_result->get_error_message()
                );
                return $wp_error;
            }

            return $user;
        }
        public function validate_register_captcha(
            $errors,
            $sanitized_user_login,
            $user_email
        ) {
            if (empty($this->options["enable_register"])) {
                return $errors;
            }

            $validation_result = $this->perform_captcha_validation();
            if (is_wp_error($validation_result)) {
                $errors->add(
                    $validation_result->get_error_code(),
                    $validation_result->get_error_message()
                );
            }
            return $errors;
        }

        // --- Métodos Activación/Desactivación/Desinstalación ---
        public static function activate()
        {
            $options = get_option(SISC_OPTIONS_NAME);
            if (false === $options) {
                $instance = self::get_instance();
                if ($instance) {
                    update_option(
                        SISC_OPTIONS_NAME,
                        $instance->get_default_options()
                    );
                } else {
                    update_option(SISC_OPTIONS_NAME, [
                        "enable_login" => 0,
                        "enable_register" => 0,
                        "enable_comments" => 0,
                        "image_source" => "custom",
                    ]);
                }
            }
            $instance = self::get_instance();
            if ($instance) {
                $instance->register_custom_taxonomy();
            }
            flush_rewrite_rules();
        }
        public static function deactivate()
        {
            flush_rewrite_rules();
        }
        public static function uninstall()
        {
            delete_option(SISC_OPTIONS_NAME);
            global $wpdb;
            $prefix = "_transient_sisc_";
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $prefix . "%"
                )
            );
            $prefix_timeout = "_transient_timeout_sisc_";
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $prefix_timeout . "%"
                )
            );
            flush_rewrite_rules();
        }

        // --- Manejo de Errores Comentarios (Transitorio) ---
        public function maybe_display_comment_captcha_error()
        {
            if ((is_single() || is_page()) && isset($_GET["sisc_error"])) {
                $transient_key = sanitize_key($_GET["sisc_error"]);
                $error_data = get_transient($transient_key);
                if (!$error_data) {
                    $current_url = remove_query_arg("sisc_error");
                    if (strpos($_SERVER["REQUEST_URI"], "#") !== false) {
                        $current_url .=
                            "#" .
                            substr(strrchr($_SERVER["REQUEST_URI"], "#"), 1);
                    } elseif (strpos($current_url, "#") === false) {
                        $current_url .= "#commentform";
                    }
                    wp_safe_redirect($current_url);
                    exit();
                }
            }
        }

        // --- Funciones para la columna de cantidad de la taxonomía ---

        /**
         * Modifica las columnas de la tabla de administración para nuestra taxonomía.
         *
         * @param array $columns Array de columnas existentes.
         * @return array Array de columnas modificado.
         */
        public function modify_taxonomy_columns($columns)
        {
            unset($columns["posts"]);
            $columns["sisc_count"] = __(
                "Image Count",
                "secure-image-sequence-captcha"
            );
            return $columns;
        }

        /**
         * Renderiza el contenido de nuestra columna personalizada en la tabla de taxonomía.
         *
         * @param string $content Contenido actual de la celda.
         * @param string $column_name Nombre de la columna actual.
         * @param int    $term_id ID del término actual.
         * @return string Contenido HTML para mostrar en la celda.
         */
        public function render_custom_taxonomy_column(
            $content,
            $column_name,
            $term_id
        ) {
            if ("sisc_count" === $column_name) {
                $query_args = [
                    "post_type" => "attachment",
                    "post_status" => "inherit",
                    "posts_per_page" => -1,
                    "tax_query" => [
                        [
                            "taxonomy" => SISC_TAXONOMY_SLUG,
                            "field" => "term_id",
                            "terms" => $term_id,
                        ],
                    ],
                    "fields" => "ids",
                    "no_found_rows" => true,
                ];
                $attachment_query = new WP_Query($query_args);
                $count = $attachment_query->post_count;
                $term = get_term($term_id);
                if (is_wp_error($term) || !$term) {
                    return $count;
                }
                $media_link = esc_url(
                    admin_url(
                        "upload.php?taxonomy=" .
                            SISC_TAXONOMY_SLUG .
                            "&term=" .
                            $term->slug
                    )
                );
                $content = sprintf('<a href="%s">%d</a>', $media_link, $count);
                return $content;
            }
            return $content;
        }
    } // Fin clase Secure_Image_Sequence_Captcha
} // Fin if class_exists

// --- Hooks e Inicialización ---
register_activation_hook(__FILE__, [
    "Secure_Image_Sequence_Captcha",
    "activate",
]);
register_deactivation_hook(__FILE__, [
    "Secure_Image_Sequence_Captcha",
    "deactivate",
]);
register_uninstall_hook(__FILE__, [
    "Secure_Image_Sequence_Captcha",
    "uninstall",
]);

function sisc_run_plugin()
{
    Secure_Image_Sequence_Captcha::get_instance();
}
add_action("plugins_loaded", "sisc_run_plugin");
// --- Fin del Archivo del Plugin ---
