<?php
/**
 * Plugin Name:       Secure Image Sequence CAPTCHA
 * Plugin URI:        https://example.com/plugins/secure-image-sequence-captcha/
 * Description:       Protege formularios de Comentarios, Login y Registro con un CAPTCHA seguro basado en secuencias de imágenes.
 * Version:           1.5.0
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

define("SISC_VERSION", "1.5.0"); // Versión incrementada
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

            // --- INICIO CORRECCIÓN XML-RPC: Hook de bloqueo temprano para XML-RPC ---
            add_action('init', [$this, '_block_xmlrpc_if_locked']);
            // --- FIN CORRECCIÓN XML-RPC ---

            // Hook para limpiar los intentos fallidos tras un login exitoso.
            add_action(
                "wp_login",
                [$this, "clear_failed_attempts_on_success"],
                10,
                2
            );

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

            // --- INICIO: Hooks para Login Lockdown ---
            if (!empty($this->options["enable_login_lockdown"])) {
                // Se engancha ANTES de procesar credenciales para bloquear si la IP ya está vetada.
                add_filter("authenticate", [$this, "check_ip_lockdown"], 20, 1);

                // Se engancha cuando WordPress confirma un fallo de inicio de sesión.
                add_action(
                    "wp_login_failed",
                    [$this, "record_failed_login_attempt"],
                    10,
                    1
                );

                // --- INICIO CORRECCIÓN XML-RPC: Registrar fallos de login en XML-RPC ---
                add_action('xmlrpc_login_error', [$this, '_record_failed_attempt']);
                // --- FIN CORRECCIÓN XML-RPC ---

                // Se engancha cuando un usuario inicia sesión con éxito para limpiar el contador.
                add_action(
                    "wp_login",
                    [$this, "clear_failed_attempts_on_success"],
                    10,
                    2
                );
            }
            // --- FIN: Hooks para Login Lockdown ---
        }
        
        // --- INICIO CORRECCIÓN XML-RPC: Nueva función para bloquear XML-RPC si la IP está vetada ---
        /**
         * Bloquea el acceso a XML-RPC si la IP del solicitante está actualmente bloqueada.
         * Se engancha en 'init' para ejecutarse temprano en las peticiones XML-RPC.
         */
        public function _block_xmlrpc_if_locked() {
            // Solo actuar en peticiones XML-RPC y si el lockdown está activo
            if ( defined('XMLRPC_REQUEST') && XMLRPC_REQUEST && !empty($this->options['enable_login_lockdown']) ) {
                if ( $this->_is_ip_locked() ) {
                    // Cargar la librería necesaria para crear un error XML-RPC
                    include_once( ABSPATH . WPINC . '/class-IXR.php' );
                    $error = new IXR_Error( 403, __( 'Your IP address has been temporarily blocked due to too many failed login attempts.', 'secure-image-sequence-captcha' ) );
                    
                    // Detener la ejecución y enviar una respuesta de error XML-RPC formateada correctamente
                    wp_die( $error->getXml(), '', array('response' => 403) );
                }
            }
        }
        // --- FIN CORRECCIÓN XML-RPC ---

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
                "image_source" => "predefined",
                // --- INICIO: Opciones de Lockdown ---
                "enable_login_lockdown" => 0,
                "login_lockdown_attempts" => 5,
                "login_lockdown_duration" => 15,
                "ip_source" => "remote_addr", // Opción por defecto segura
                // --- FIN: Opciones de Lockdown ---
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
                "show_in_rest" => false,
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

            // --- Sección de Activación (Existente) ---
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

            // --- Sección de Fuente de Imágenes (Existente) ---
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

            // --- Sección Login Lockdown (Modificada) ---
            add_settings_section(
                "sisc_section_lockdown",
                __(
                    "Login Lockdown (Brute-Force Protection)",
                    "secure-image-sequence-captcha"
                ),
                [$this, "render_section_lockdown_cb"],
                SISC_SETTINGS_SLUG
            );

            add_settings_field(
                "sisc_field_enable_login_lockdown",
                __("Enable Lockdown", "secure-image-sequence-captcha"),
                [$this, "render_field_checkbox_cb"],
                SISC_SETTINGS_SLUG,
                "sisc_section_lockdown",
                [
                    "label_for" => "sisc_enable_login_lockdown",
                    "option_name" => "enable_login_lockdown",
                    "description" => __(
                        "Temporarily blocks an IP address after multiple failed login attempts.",
                        "secure-image-sequence-captcha"
                    ),
                ]
            );

            add_settings_field(
                "sisc_field_login_lockdown_attempts",
                __(
                    "Failed Attempts Threshold",
                    "secure-image-sequence-captcha"
                ),
                [$this, "render_field_number_cb"],
                SISC_SETTINGS_SLUG,
                "sisc_section_lockdown",
                [
                    "label_for" => "sisc_login_lockdown_attempts",
                    "option_name" => "login_lockdown_attempts",
                    "description" => __(
                        "Block an IP after this many failed attempts.",
                        "secure-image-sequence-captcha"
                    ),
                    "min" => 2,
                    "max" => 100,
                ]
            );

            add_settings_field(
                "sisc_field_login_lockdown_duration",
                __("Lockdown Duration", "secure-image-sequence-captcha"),
                [$this, "render_field_number_cb"],
                SISC_SETTINGS_SLUG,
                "sisc_section_lockdown",
                [
                    "label_for" => "sisc_login_lockdown_duration",
                    "option_name" => "login_lockdown_duration",
                    "description" => __(
                        "Block the IP for this many minutes.",
                        "secure-image-sequence-captcha"
                    ),
                    "min" => 1,
                    "max" => 1440, // 24 horas
                ]
            );

            // --- INICIO: Nuevo campo para Fuente de IP ---
            add_settings_field(
                "sisc_field_ip_source",
                __("Client IP Source", "secure-image-sequence-captcha"),
                [$this, "render_field_ip_source_cb"],
                SISC_SETTINGS_SLUG,
                "sisc_section_lockdown",
                [
                    "label_for" => "sisc_ip_source",
                ]
            );
            // --- FIN: Nuevo campo para Fuente de IP ---
        }

        public function render_section_lockdown_cb($args)
        {
            echo '<p id="' .
                esc_attr($args["id"]) .
                '-description">' .
                esc_html__(
                    "This feature enhances security by preventing brute-force attacks on the login form.",
                    "secure-image-sequence-captcha"
                ) .
                "</p>";
        }

        public function render_field_number_cb($args)
        {
            $option_name = $args["option_name"];
            $label_for = $args["label_for"];
            $option_key = SISC_OPTIONS_NAME;
            $current_value = isset($this->options[$option_name])
                ? intval($this->options[$option_name])
                : 0;
            $description = isset($args["description"])
                ? $args["description"]
                : "";
            $min = isset($args["min"]) ? intval($args["min"]) : 0;
            $max = isset($args["max"]) ? intval($args["max"]) : 999;

            echo '<input type="number" id="' .
                esc_attr($label_for) .
                '" name="' .
                esc_attr($option_key . "[" . $option_name . "]") .
                '" value="' .
                esc_attr($current_value) .
                '" min="' .
                esc_attr($min) .
                '" max="' .
                esc_attr($max) .
                '" class="small-text" />';

            if (!empty($description)) {
                echo '<p class="description">' .
                    wp_kses_post($description) .
                    "</p>";
            }
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

            // Opciones existentes
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

            $sanitized_input["enable_login_lockdown"] =
                isset($input["enable_login_lockdown"]) &&
                $input["enable_login_lockdown"] == "1"
                    ? 1
                    : 0;

            if (isset($input["login_lockdown_attempts"])) {
                $sanitized_input["login_lockdown_attempts"] = absint(
                    $input["login_lockdown_attempts"]
                );
                if ($sanitized_input["login_lockdown_attempts"] < 2) {
                    $sanitized_input["login_lockdown_attempts"] =
                        $defaults["login_lockdown_attempts"];
                }
            } else {
                $sanitized_input["login_lockdown_attempts"] =
                    $defaults["login_lockdown_attempts"];
            }

            if (isset($input["login_lockdown_duration"])) {
                $sanitized_input["login_lockdown_duration"] = absint(
                    $input["login_lockdown_duration"]
                );
                if ($sanitized_input["login_lockdown_duration"] < 1) {
                    $sanitized_input["login_lockdown_duration"] =
                        $defaults["login_lockdown_duration"];
                }
            } else {
                $sanitized_input["login_lockdown_duration"] =
                    $defaults["login_lockdown_duration"];
            }

            // --- INICIO: Sanitización para la fuente de IP ---
            $allowed_ip_sources = [
                "remote_addr",
                "cf_connecting_ip",
                "x_forwarded_for",
                "x_real_ip",
            ];
            if (
                isset($input["ip_source"]) &&
                in_array($input["ip_source"], $allowed_ip_sources, true)
            ) {
                $sanitized_input["ip_source"] = sanitize_key(
                    $input["ip_source"]
                );
            } else {
                $sanitized_input["ip_source"] = $defaults["ip_source"]; // Fallback seguro
            }
            // --- FIN: Sanitización para la fuente de IP ---

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
            $image_source = isset($this->options["image_source"])
                ? $this->options["image_source"]
                : "custom";

            $image_details = false;

            if ("custom" === $image_source) {
                $status = $this->get_custom_category_status();

                if (!empty($status["valid"])) {
                    $valid_category_ids = wp_list_pluck(
                        $status["valid"],
                        "term_id"
                    );
                    $random_term_id =
                        $valid_category_ids[array_rand($valid_category_ids)];

                    $query_args = [
                        "post_type" => "attachment",
                        "post_status" => "inherit",
                        "posts_per_page" => 50,
                        "tax_query" => [
                            [
                                "taxonomy" => SISC_TAXONOMY_SLUG,
                                "field" => "term_id",
                                "terms" => $random_term_id,
                            ],
                        ],
                        "fields" => "ids",
                        "orderby" => "rand",
                    ];
                    $image_query = new WP_Query($query_args);
                    $all_image_ids = $image_query->posts;
                    wp_reset_postdata();

                    $selected_image_ids = array_slice(
                        $all_image_ids,
                        0,
                        SISC_TOTAL_IMAGES
                    );

                    if (count($selected_image_ids) === SISC_TOTAL_IMAGES) {
                        $image_details = [
                            "source_type" => "custom",
                            "source_id" => $random_term_id,
                            "selected_identifiers" => $selected_image_ids,
                            "correct_identifiers" => array_slice(
                                $selected_image_ids,
                                0,
                                SISC_IMAGES_IN_SEQUENCE
                            ),
                        ];
                    }
                } else {
                    error_log(
                        "[SISC] Custom source failed: No valid categories found. Attempting to fall back to predefined sets."
                    );
                }
            }

            if (false === $image_details) {
                $image_details = $this->_get_predefined_challenge_data();
            }

            if (false === $image_details) {
                error_log(
                    "[SISC] FATAL: CAPTCHA generation failed. No valid image sources available (custom or predefined)."
                );
                return false;
            }

            $challenge_images = [];
            $temporal_id_map = [];
            $correct_temporal_sequence_map = [];

            foreach ($image_details["selected_identifiers"] as $identifier) {
                $temporal_id = bin2hex(random_bytes(8));

                if ("predefined" === $image_details["source_type"]) {
                    $filename = basename($identifier);
                    $image_url =
                        SISC_PREDEFINED_IMAGES_URL .
                        $image_details["source_id"] .
                        "/" .
                        $filename;
                    $alt_text = ucfirst(
                        str_replace(
                            ["-", "_"],
                            " ",
                            pathinfo($filename, PATHINFO_FILENAME)
                        )
                    );
                    $temporal_id_map[$temporal_id] = $filename;
                } else {
                    $image_url = wp_get_attachment_image_url(
                        $identifier,
                        SISC_IMAGE_SIZE
                    );
                    $alt_text =
                        get_post_meta(
                            $identifier,
                            "_wp_attachment_image_alt",
                            true
                        ) ?:
                        get_the_title($identifier);
                    $temporal_id_map[$temporal_id] = $identifier;
                }

                if (!$image_url) {
                    continue;
                }

                $challenge_images[] = [
                    "temp_id" => $temporal_id,
                    "url" => $image_url,
                    "alt" =>
                        $alt_text ?:
                        __("CAPTCHA Image", "secure-image-sequence-captcha"),
                ];

                $correct_pos = array_search(
                    $identifier,
                    $image_details["correct_identifiers"]
                );
                if ($correct_pos !== false) {
                    $correct_temporal_sequence_map[$correct_pos] = $temporal_id;
                }
            }

            if ("predefined" === $image_details["source_type"]) {
                $correct_image_titles = array_map(function ($filepath) {
                    return ucfirst(
                        str_replace(
                            ["-", "_"],
                            " ",
                            pathinfo(basename($filepath), PATHINFO_FILENAME)
                        )
                    );
                }, $image_details["correct_identifiers"]);
            } else {
                $correct_image_titles = array_map(
                    "get_the_title",
                    $image_details["correct_identifiers"]
                );
            }

            ksort($correct_temporal_sequence_map);
            $correct_temporal_sequence = array_values(
                $correct_temporal_sequence_map
            );

            if (
                count($challenge_images) !== SISC_TOTAL_IMAGES ||
                count($correct_temporal_sequence) !== SISC_IMAGES_IN_SEQUENCE
            ) {
                error_log(
                    "[SISC] Final consistency check failed. Aborting CAPTCHA generation."
                );
                return false;
            }

            $valid_titles = array_filter($correct_image_titles);
            if (count($valid_titles) !== SISC_IMAGES_IN_SEQUENCE) {
                $question = __(
                    "Click the images in the correct sequence.",
                    "secure-image-sequence-captcha"
                );
            } else {
                $question = sprintf(
                    __(
                        "Click the images in this order: %s",
                        "secure-image-sequence-captcha"
                    ),
                    implode(", ", $valid_titles)
                );
            }
            
            // --- INICIO CORRECCIÓN: FORTALECIMIENTO DE SEGURIDAD (SOLUCIONES HASHEADAS) ---
            // Se hashea la secuencia correcta antes de guardarla en el transitorio.
            $correct_sequence_string = implode(",", $correct_temporal_sequence);
            $hashed_sequence = wp_hash_password($correct_sequence_string);

            $transient_data = [
                "correct_sequence_hash" => $hashed_sequence, // Guardamos el hash, no el texto plano
                "temporal_map" => $temporal_id_map,
                "timestamp" => time(),
                "source_type" => $image_details["source_type"],
                "source_id" => $image_details["source_id"],
            ];
            // --- FIN CORRECCIÓN ---

            $transient_key = "sisc_ch_" . bin2hex(random_bytes(12));
            set_transient(
                $transient_key,
                $transient_data,
                SISC_TRANSIENT_EXPIRATION
            );

            $nonce = wp_create_nonce(SISC_NONCE_ACTION . "_" . $transient_key);
            shuffle($challenge_images);

            return [
                "question" => $question,
                "images" => $challenge_images,
                "nonce" => $nonce,
                "transient_key" => $transient_key,
            ];
        }

        private function _get_predefined_challenge_data()
        {
            if (
                !is_dir(SISC_PREDEFINED_IMAGES_DIR) ||
                !is_readable(SISC_PREDEFINED_IMAGES_DIR)
            ) {
                error_log(
                    "[SISC] Predefined directory error: " .
                        SISC_PREDEFINED_IMAGES_DIR
                );
                return false;
            }

            $available_sets_paths = [];
            $all_items = scandir(SISC_PREDEFINED_IMAGES_DIR);

            if (false === $all_items) {
                error_log(
                    "[SISC] Scandir error on: " . SISC_PREDEFINED_IMAGES_DIR
                );
                return false;
            }

            foreach ($all_items as $item) {
                if (
                    $item === "." ||
                    $item === ".." ||
                    strpos($item, ".") === 0
                ) {
                    continue;
                }
                $potential_set_path = SISC_PREDEFINED_IMAGES_DIR . $item;
                if (
                    is_dir($potential_set_path) &&
                    is_readable($potential_set_path)
                ) {
                    $image_files_in_set = glob(
                        $potential_set_path . "/*.{jpg,jpeg,png,gif,webp}",
                        GLOB_BRACE
                    );
                    if (
                        !empty($image_files_in_set) &&
                        count($image_files_in_set) >= SISC_TOTAL_IMAGES
                    ) {
                        $available_sets_paths[] = $potential_set_path;
                    }
                }
            }

            if (empty($available_sets_paths)) {
                error_log(
                    "[SISC] Predefined source failed: No valid sets with enough images found."
                );
                return false;
            }

            $random_set_path =
                $available_sets_paths[array_rand($available_sets_paths)];
            $set_name = basename($random_set_path);
            $image_files = glob(
                $random_set_path . "/*.{jpg,jpeg,png,gif,webp}",
                GLOB_BRACE
            );

            if (empty($image_files)) {
                error_log(
                    "[SISC] Glob error in predefined set: " . $random_set_path
                );
                return false;
            }

            shuffle($image_files);
            $selected_image_files = array_slice(
                $image_files,
                0,
                SISC_TOTAL_IMAGES
            );

            return [
                "source_type" => "predefined",
                "source_id" => $set_name,
                "selected_identifiers" => $selected_image_files,
                "correct_identifiers" => array_slice(
                    $selected_image_files,
                    0,
                    SISC_IMAGES_IN_SEQUENCE
                ),
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
            // --- INICIO: VERIFICACIÓN DE BLOQUEO DE IP ---
            if ($this->_is_ip_locked()) {
                // Mandamiento de Seguridad: Saneamiento en cada salida (Output Escaping).
                echo '<p class="sisc-error"><strong>' .
                    esc_html__(
                        "Access Denied:",
                        "secure-image-sequence-captcha"
                    ) .
                    "</strong> " .
                    esc_html__(
                        "Too many failed attempts. Your IP is temporarily blocked.",
                        "secure-image-sequence-captcha"
                    ) .
                    "</p>";
                return;
            }
            // --- FIN: VERIFICACIÓN DE BLOQUEO DE IP ---

            if ($this->_is_rate_limited()) {
                echo '<p class="sisc-error"><em>' .
                    esc_html__(
                        "Too many requests. Please wait a moment.",
                        "secure-image-sequence-captcha"
                    ) .
                    "</em></p>";
                return;
            }

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
            // --- INICIO: NUEVA VERIFICACIÓN DE BLOQUEO DE IP ---
            // Mandamiento de Seguridad: Primero, verificamos el estado antes de gastar recursos.
            // Si la IP ya está bloqueada, no tiene sentido generar un CAPTCHA.
            if ($this->_is_ip_locked()) {
                // Mandamiento de Seguridad: Saneamiento en cada salida (Output Escaping).
                // Usamos esc_html__() para imprimir texto traducible de forma segura, previniendo ataques XSS.
                echo '<p class="sisc-error login-error"><strong>' .
                    esc_html__(
                        "Access Denied:",
                        "secure-image-sequence-captcha"
                    ) .
                    "</strong> " .
                    esc_html__(
                        "Too many failed login attempts. Your IP is temporarily blocked.",
                        "secure-image-sequence-captcha"
                    ) .
                    "</p><br><br>";
                // Detenemos la ejecución aquí para ahorrar recursos del servidor.
                return;
            }
            // --- FIN: NUEVA VERIFICACIÓN DE BLOQUEO DE IP ---

            // El resto del código solo se ejecuta si la IP NO está bloqueada.
            if ($this->_is_rate_limited()) {
                echo '<p class="sisc-error login-error"><em>' .
                    esc_html__(
                        "Too many requests. Please wait a moment.",
                        "secure-image-sequence-captcha"
                    ) .
                    "</em></p>";
                return;
            }

            $challenge_data = $this->generate_captcha_challenge();
            if (!$challenge_data) {
                echo '<p class="sisc-error login-error"><em>' .
                    esc_html__(
                        "CAPTCHA generation failed due to a configuration issue. Submission is blocked.",
                        "secure-image-sequence-captcha"
                    ) .
                    "</em></p>";
                // Mandamiento de Seguridad: Desconfía de toda entrada (Input Validation).
                // Aunque aquí la entrada es fija, usamos esc_attr() en el HTML para mantener la consistencia.
                echo '<input type="hidden" name="sisc_transient_key" value="sisc_generation_failed">';
                return;
            }
            echo '<div style="margin-bottom: 15px;">';
            $this->render_captcha_html($challenge_data, "login");
            echo "</div>";
        }

        public function display_captcha_in_register()
        {
            // --- INICIO: VERIFICACIÓN DE BLOQUEO DE IP ---
            if ($this->_is_ip_locked()) {
                // Mandamiento de Seguridad: Saneamiento en cada salida (Output Escaping).
                echo '<p class="sisc-error register-error"><strong>' .
                    esc_html__(
                        "Access Denied:",
                        "secure-image-sequence-captcha"
                    ) .
                    "</strong> " .
                    esc_html__(
                        "Too many failed attempts. Your IP is temporarily blocked.",
                        "secure-image-sequence-captcha"
                    ) .
                    "</p>";
                return;
            }
            // --- FIN: VERIFICACIÓN DE BLOQUEO DE IP ---

            if ($this->_is_rate_limited()) {
                echo '<p class="sisc-error register-error"><em>' .
                    esc_html__(
                        "Too many requests. Please wait a moment.",
                        "secure-image-sequence-captcha"
                    ) .
                    "</em></p>";
                return;
            }

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

        // --- INICIO: Funciones de Lógica de Login Lockdown ---

        /**
         * Obtiene la dirección IP real del usuario basándose en la configuración del plugin.
         *
         * @return string La IP del usuario o una cadena vacía si no es válida.
         */
        /**
         * Obtiene la dirección IP real del usuario de forma segura.
         *
         * Solo confiará en una cabecera de proxy
         * si la configuración del usuario lo indica Y si hay evidencia clara de un proxy
         * (es decir, la IP de la cabecera del proxy es diferente de REMOTE_ADDR).
         * Si estas condiciones no se cumplen, IGNORA la configuración del usuario y
         * siempre devuelve REMOTE_ADDR como la única fuente de verdad.
         *
         * @return string La IP del usuario o una cadena vacía si no es válida.
         */
        private function get_user_ip()
        {
            $user_selected_source = isset($this->options["ip_source"])
                ? $this->options["ip_source"]
                : "remote_addr";

            // Obtener la IP de REMOTE_ADDR es siempre el primer paso y nuestra base de seguridad.
            $remote_addr_ip = "";
            if (
                isset($_SERVER["REMOTE_ADDR"]) &&
                filter_var($_SERVER["REMOTE_ADDR"], FILTER_VALIDATE_IP)
            ) {
                $remote_addr_ip = sanitize_text_field(
                    wp_unslash($_SERVER["REMOTE_ADDR"])
                );
            }

            // Si el usuario no ha seleccionado una cabecera de proxy, el trabajo termina aquí.
            if ("remote_addr" === $user_selected_source) {
                return $remote_addr_ip;
            }

            // El usuario ha seleccionado una cabecera de proxy. Ahora debemos verificarla.
            $header_to_check = "";
            switch ($user_selected_source) {
                case "cf_connecting_ip":
                    $header_to_check = "HTTP_CF_CONNECTING_IP";
                    break;
                case "x_forwarded_for":
                    $header_to_check = "HTTP_X_FORWARDED_FOR";
                    break;
                case "x_real_ip":
                    $header_to_check = "HTTP_X_REAL_IP";
                    break;
            }

            if (empty($header_to_check) || !isset($_SERVER[$header_to_check])) {
                // La cabecera seleccionada no existe. Volvemos a la opción segura.
                return $remote_addr_ip;
            }

            // Extraemos y validamos la IP de la cabecera del proxy.
            $proxy_header_ip = "";
            $raw_ip = sanitize_text_field(
                wp_unslash($_SERVER[$header_to_check])
            );
            $ip_parts = explode(",", $raw_ip);
            $candidate_ip = trim($ip_parts[0]);

            if (filter_var($candidate_ip, FILTER_VALIDATE_IP)) {
                $proxy_header_ip = $candidate_ip;
            }

            // Solo aceptamos la IP del proxy si es válida Y si es diferente de REMOTE_ADDR.
            // Esta diferencia es la evidencia de que estamos realmente detrás de un proxy.
            // Si son iguales, o si alguna es inválida, significa que la cabecera puede haber sido
            // falsificada por el cliente, por lo que la ignoramos por seguridad.
            if (
                $proxy_header_ip &&
                $remote_addr_ip &&
                $proxy_header_ip !== $remote_addr_ip
            ) {
                return $proxy_header_ip;
            }

            // Si no pasamos la verificación de seguridad, siempre volvemos a la IP de la conexión directa.
            return $remote_addr_ip;
        }

        /**
         * Verifica si una IP ha excedido el límite de generación de CAPTCHA.
         *
         * @return bool True si está limitado, false en caso contrario.
         */
        private function _is_rate_limited()
        {
            $ip = $this->get_user_ip();
            if (empty($ip)) {
                return false; // No podemos limitar sin una IP.
            }

            $rate_limit_key = "sisc_gen_limit_" . md5($ip);
            $attempts = (int) get_transient($rate_limit_key);
            $limit = 15; // Límite: 15 generaciones por minuto por IP.

            if ($attempts >= $limit) {
                // Registrar solo si es la primera vez que se excede para no llenar el log.
                if ($attempts === $limit) {
                    error_log(
                        "[SISC] Rate limit of {$limit}/min exceeded for IP: " .
                            $ip
                    );
                    // Se establece un transitorio más largo para el estado "excedido".
                    set_transient(
                        $rate_limit_key,
                        $attempts + 1,
                        5 * MINUTE_IN_SECONDS
                    );
                }
                return true;
            }

            set_transient($rate_limit_key, $attempts + 1, MINUTE_IN_SECONDS);
            return false;
        }

        /**
         * Verifica si la IP del usuario actual está bloqueada. Se engancha en 'authenticate'.
         *
         * @param WP_User|WP_Error|null $user
         * @return WP_User|WP_Error|null
         */
        public function check_ip_lockdown($user)
        {
            $ip = $this->get_user_ip();
            if (empty($ip)) {
                return $user;
            }
            $lock_transient_key = "sisc_ip_lock_" . md5($ip);
            if (get_transient($lock_transient_key)) {
                return new WP_Error(
                    "sisc_ip_locked",
                    "<strong>" .
                        esc_html__("ERROR:", "secure-image-sequence-captcha") .
                        "</strong> " .
                        esc_html__(
                            "Too many failed login attempts. Please try again later.",
                            "secure-image-sequence-captcha"
                        )
                );
            }
            return $user;
        }

        /**
         * Registra un intento de inicio de sesión fallido. Se engancha en 'wp_login_failed'.
         *
         * @param string $username El nombre de usuario que falló.
         */
        /**
         * Registra un intento fallido desde cualquier fuente (login, CAPTCHA).
         * Esta es la función central de la lógica de lockdown.
         *
         * @since 1.4.3
         */
        private function _record_failed_attempt()
        {
            // Mandamiento de Seguridad: La lógica solo se ejecuta si la función está habilitada.
            if (empty($this->options["enable_login_lockdown"])) {
                return;
            }

            $ip = $this->get_user_ip();
            if (empty($ip)) {
                return; // No podemos bloquear sin una IP.
            }

            // Usamos absint() para asegurar que los valores de las opciones son enteros.
            $defaults = $this->get_default_options();
            $attempts_limit = isset($this->options["login_lockdown_attempts"])
                ? absint($this->options["login_lockdown_attempts"])
                : $defaults["login_lockdown_attempts"];
            $duration_minutes = isset($this->options["login_lockdown_duration"])
                ? absint($this->options["login_lockdown_duration"])
                : $defaults["login_lockdown_duration"];

            $count_transient_key = "sisc_failed_count_" . md5($ip);
            $failed_attempts = get_transient($count_transient_key);
            $failed_attempts = $failed_attempts
                ? absint($failed_attempts) + 1
                : 1;

            if ($failed_attempts >= $attempts_limit) {
                // Se ha alcanzado el umbral. Bloqueamos la IP.
                $lock_transient_key = "sisc_ip_lock_" . md5($ip);
                // Mandamiento de Seguridad: Usamos las APIs de WordPress (set_transient) para la persistencia de datos.
                set_transient(
                    $lock_transient_key,
                    true,
                    $duration_minutes * MINUTE_IN_SECONDS
                );
                delete_transient($count_transient_key); // Limpiamos el contador una vez bloqueado.
            } else {
                // Aún no se ha alcanzado el umbral, solo incrementamos el contador.
                set_transient(
                    $count_transient_key,
                    $failed_attempts,
                    $duration_minutes * MINUTE_IN_SECONDS
                );
            }
        }

        /**
         * Registra un intento de inicio de sesión fallido. Se engancha en 'wp_login_failed'.
         *
         * @param string $username El nombre de usuario que falló.
         */
        public function record_failed_login_attempt($username)
        {
            // Simplemente llama a la nueva función centralizada.
            $this->_record_failed_attempt();
        }

        /**
         * Limpia los intentos de inicio de sesión fallidos para una IP tras un inicio de sesión exitoso.
         * Se engancha en 'wp_login'.
         *
         * @param string  $user_login El login del usuario.
         * @param WP_User $user       El objeto del usuario.
         */
        public function clear_failed_attempts_on_success($user_login, $user)
        {
            $ip = $this->get_user_ip();
            if (!empty($ip)) {
                $count_transient_key = "sisc_failed_count_" . md5($ip);
                delete_transient($count_transient_key);
            }
        }
        // --- FIN: Funciones de Lógica de Login Lockdown ---

        /**
         * Renderiza el campo de selección para la fuente de la IP, incluyendo la herramienta de diagnóstico.
         *
         * @param array $args Argumentos del campo.
         */
        /**
         * Renderiza el campo de selección para la fuente de la IP, incluyendo la herramienta de diagnóstico.
         * ESTA FUNCIÓN SE MANTIENE INTACTA, ya que su única labor es llamar a las funciones de diagnóstico y renderizado,
         * las cuales han sido mejoradas.
         *
         * @param array $args Argumentos del campo.
         */
        public function render_field_ip_source_cb($args)
        {
            $option_name = "ip_source";
            $label_for = $args["label_for"];
            $option_key = SISC_OPTIONS_NAME;
            $current_value = isset($this->options[$option_name])
                ? $this->options[$option_name]
                : "remote_addr";

            // 1. OBTENER DATOS DE DIAGNÓSTICO
            $detected_ips = $this->_get_ip_diagnostic_data();
            $recommendation = $this->_generate_ip_source_recommendation(
                $detected_ips,
                $current_value
            );
            // 2. MOSTRAR LA HERRAMIENTA DE DIAGNÓSTICO
            ?>
    <div class="sisc-diag-box" style="border: 1px solid #c3c4c7; padding: 15px; margin-bottom: 20px; background-color: #f9f9f9; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
        <h4 style="margin-top: 0; border-bottom: 1px solid #ddd; padding-bottom: 8px;"><?php esc_html_e(
            "IP Detection Diagnostics",
            "secure-image-sequence-captcha"
        ); ?></h4>
        <p class="description"><?php esc_html_e(
            "Use this tool to select the correct option. Your current IP, as seen by the server from different sources, is:",
            "secure-image-sequence-captcha"
        ); ?></p>
        <ul style="list-style: none; margin-left: 0; padding-left: 0;">
            <li style="margin-bottom: 5px;"><strong>REMOTE_ADDR:</strong> <code style="background: #eee; padding: 2px 5px;"><?php echo esc_html(
                $detected_ips["remote_addr"] ??
                    __("Not detected", "secure-image-sequence-captcha")
            ); ?></code></li>
            <li style="margin-bottom: 5px;"><strong>HTTP_CF_CONNECTING_IP:</strong> <code style="background: #eee; padding: 2px 5px;"><?php echo esc_html(
                $detected_ips["cf_connecting_ip"] ??
                    __("Not detected", "secure-image-sequence-captcha")
            ); ?></code></li>
            <li style="margin-bottom: 5px;"><strong>HTTP_X_FORWARDED_FOR:</strong> <code style="background: #eee; padding: 2px 5px;"><?php echo esc_html(
                $detected_ips["x_forwarded_for"] ??
                    __("Not detected", "secure-image-sequence-captcha")
            ); ?></code></li>
            <li style="margin-bottom: 5px;"><strong>HTTP_X_REAL_IP:</strong> <code style="background: #eee; padding: 2px 5px;"><?php echo esc_html(
                $detected_ips["x_real_ip"] ??
                    __("Not detected", "secure-image-sequence-captcha")
            ); ?></code></li>
        </ul>
        <div class="notice notice-<?php echo esc_attr(
            $recommendation["type"]
        ); ?> inline" style="margin: 10px 0 0 0;">
            <p><?php echo wp_kses_post($recommendation["message"]); ?></p>
        </div>
    </div>
    <?php
    // 3. RENDERIZAR EL MENÚ DESPLEGABLE
    $ip_sources = [
        "remote_addr" => __(
            "Standard (REMOTE_ADDR)",
            "secure-image-sequence-captcha"
        ),
        "cf_connecting_ip" => __(
            "Cloudflare (HTTP_CF_CONNECTING_IP)",
            "secure-image-sequence-captcha"
        ),
        "x_forwarded_for" => __(
            "Reverse Proxy (HTTP_X_FORWARDED_FOR)",
            "secure-image-sequence-captcha"
        ),
        "x_real_ip" => __(
            "Reverse Proxy (HTTP_X_REAL_IP)",
            "secure-image-sequence-captcha"
        ),
    ];

    echo '<select id="' .
        esc_attr($label_for) .
        '" name="' .
        esc_attr($option_key . "[" . $option_name . "]") .
        '">';
    foreach ($ip_sources as $value => $label) {
        echo '<option value="' .
            esc_attr($value) .
            '" ' .
            selected($current_value, $value, false) .
            ">" .
            esc_html($label) .
            "</option>";
    }
    echo "</select>";
        }
        /**
         * Recopila y valida las IPs del visitante desde varias fuentes de servidor.
         *
         * @return array Un array con las IPs detectadas. null si no se detecta o no es válida.
         */
        private function _get_ip_diagnostic_data()
        {
            $headers_to_check = [
                "remote_addr" => "REMOTE_ADDR",
                "cf_connecting_ip" => "HTTP_CF_CONNECTING_IP",
                "x_forwarded_for" => "HTTP_X_FORWARDED_FOR",
                "x_real_ip" => "HTTP_X_REAL_IP",
            ];

            $detected_ips = [];

            foreach ($headers_to_check as $key => $server_key) {
                $ip_address = null;
                if (isset($_SERVER[$server_key])) {
                    $raw_ip = sanitize_text_field(
                        wp_unslash($_SERVER[$server_key])
                    );
                    $ip_parts = explode(",", $raw_ip);
                    $candidate_ip = trim($ip_parts[0]);
                    if (filter_var($candidate_ip, FILTER_VALIDATE_IP)) {
                        $ip_address = $candidate_ip;
                    }
                }
                $detected_ips[$key] = $ip_address;
            }

            return $detected_ips;
        }

        /**
         * Analiza las IPs detectadas y genera una recomendación de seguridad inteligente y útil.
         *
         * @param array $detected_ips IPs obtenidas de _get_ip_diagnostic_data().
         * @param string $current_setting La opción de fuente de IP actualmente guardada.
         * @return array Un array con el mensaje de recomendación y el tipo de aviso ('success', 'warning', 'info').
         */
        private function _generate_ip_source_recommendation(
            $detected_ips,
            $current_setting
        ) {
            $remote_addr = $detected_ips["remote_addr"];
            $proxy_headers_in_use = [
                "cf_connecting_ip" => $detected_ips["cf_connecting_ip"],
                "x_forwarded_for" => $detected_ips["x_forwarded_for"],
                "x_real_ip" => $detected_ips["x_real_ip"],
            ];

            // Escenario 1: Estamos detrás de un proxy.
            // La evidencia es que REMOTE_ADDR existe, una cabecera de proxy existe, y son diferentes.
            $real_ip_from_proxy = null;
            $recommended_key = null;
            $recommended_name = "";

            foreach ($proxy_headers_in_use as $key => $ip) {
                if ($ip && $remote_addr && $ip !== $remote_addr) {
                    $real_ip_from_proxy = $ip; // Encontramos la IP real del visitante.
                    $recommended_key = $key;
                    break;
                }
            }

            if ($recommended_key) {
                $source_names = [
                    "cf_connecting_ip" => "Cloudflare (HTTP_CF_CONNECTING_IP)",
                    "x_forwarded_for" => "Reverse Proxy (HTTP_X_FORWARDED_FOR)",
                    "x_real_ip" => "Reverse Proxy (HTTP_X_REAL_IP)",
                ];
                $recommended_name = $source_names[$recommended_key];

                if ($current_setting === $recommended_key) {
                    return [
                        "type" => "success",
                        "message" =>
                            "<strong>" .
                            esc_html__(
                                "Configuration Correct.",
                                "secure-image-sequence-captcha"
                            ) .
                            "</strong> " .
                            esc_html__(
                                "Your setting matches our recommendation for your proxy environment.",
                                "secure-image-sequence-captcha"
                            ),
                    ];
                } else {
                    return [
                        "type" => "warning",
                        "message" => sprintf(
                            '<strong>%1$s</strong> %2$s <strong>"%3$s"</strong>. %4$s',
                            esc_html__(
                                "Action Required!",
                                "secure-image-sequence-captcha"
                            ),
                            esc_html__(
                                "We detect a proxy. For the security lockdown to work, please select",
                                "secure-image-sequence-captcha"
                            ),
                            esc_html($recommended_name),
                            esc_html__(
                                "Your current selection is not optimal.",
                                "secure-image-sequence-captcha"
                            )
                        ),
                    ];
                }
            }

            // Escenario 2: No parece que estemos detrás de un proxy.
            // La opción correcta DEBE ser 'remote_addr'.
            if ($current_setting === "remote_addr") {
                return [
                    "type" => "success",
                    "message" =>
                        "<strong>" .
                        esc_html__(
                            "Configuration Correct.",
                            "secure-image-sequence-captcha"
                        ) .
                        "</strong> " .
                        esc_html__(
                            "You are using the standard and most secure setting.",
                            "secure-image-sequence-captcha"
                        ),
                ];
            } else {
                return [
                    "type" => "info",
                    "message" => sprintf(
                        '<strong>%1$s</strong> %2$s <strong>"%3$s"</strong>. %4$s',
                        esc_html__(
                            "Recommendation:",
                            "secure-image-sequence-captcha"
                        ),
                        esc_html__(
                            "We do not detect a proxy. The most secure setting is",
                            "secure-image-sequence-captcha"
                        ),
                        esc_html__(
                            "Standard (REMOTE_ADDR)",
                            "secure-image-sequence-captcha"
                        ),
                        esc_html__(
                            "Your current selection might not work as expected.",
                            "secure-image-sequence-captcha"
                        )
                    ),
                ];
            }
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

            // --- INICIO CORRECCIÓN: MITIGACIÓN DE DoS ---
            // Se comprueba la validez del transitorio ANTES de borrarlo.
            if (
                false === $transient_data ||
                !is_array($transient_data) ||
                !isset($transient_data["correct_sequence_hash"]) // Verificamos el hash
            ) {
            // --- FIN CORRECCIÓN ---
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

            // --- INICIO CORRECCIÓN: FORTALECIMIENTO DE SEGURIDAD (COMPARACIÓN DE HASH) ---
            // Se utiliza wp_check_password para una comparación segura en tiempo constante.
            $correct_sequence_hash = $transient_data["correct_sequence_hash"];
            $is_correct = wp_check_password($user_sequence_raw, $correct_sequence_hash);

            if (!$is_correct) {
            // --- FIN CORRECCIÓN ---
                return new WP_Error(
                    "sisc_incorrect_sequence",
                    __(
                        "Incorrect CAPTCHA sequence. Please try again.",
                        "secure-image-sequence-captcha"
                    )
                );
            }

            // --- INICIO CORRECCIÓN: MITIGACIÓN DE DoS ---
            // El transitorio solo se elimina DESPUÉS de una validación exitosa.
            delete_transient($transient_key);
            // --- FIN CORRECCIÓN ---

            return true;
        }

        /**
         * Valida el CAPTCHA para el formulario de comentarios.
         * Si la validación falla, registra el intento fallido y redirige al usuario
         * con un mensaje de error, preservando los datos del comentario.
         *
         * @param array $commentdata Datos del comentario que se están procesando.
         * @return array|void Los datos del comentario si la validación es exitosa. Llama a wp_die() o wp_safe_redirect() en caso de error.
         */
        public function validate_comment_captcha($commentdata)
        {
            // 1. Si el CAPTCHA en comentarios no está habilitado, no hacer nada.
            if (empty($this->options["enable_comments"])) {
                return $commentdata;
            }

            // 2. Mejora: Por defecto, no mostrar CAPTCHA a usuarios conectados.
            // Esto se puede anular con un filtro si el administrador del sitio lo desea.
            // Mandamiento de Seguridad: Verificar permisos y contexto. Un usuario logueado es generalmente más confiable.
            if (
                is_user_logged_in() &&
                !apply_filters("sisc_show_for_logged_in_users_comments", true)
            ) {
                return $commentdata;
            }

            // 3. Defensa contra la manipulación del formulario. Si el campo clave del CAPTCHA no se envió,
            // es una señal de manipulación. Detenemos la ejecución de forma abrupta.
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

            // 4. Realizar la validación del CAPTCHA.
            $validation_result = $this->perform_captcha_validation();

            // 5. Si la validación falla...
            if (is_wp_error($validation_result)) {
                // --- ¡ACCIÓN CLAVE DE SEGURIDAD! ---
                // Registramos este intento fallido en nuestro sistema de bloqueo de IP.
                $this->_record_failed_attempt();
                // --- FIN DE LA ACCIÓN ---

                $error_code = $validation_result->get_error_code();
                $error_message = $validation_result->get_error_message();

                // 6. Para mejorar la UX, preservamos los datos del comentario en un transitorio.
                // Mandamiento de Seguridad: Desconfía de toda entrada. Aunque vamos a preservar, no ejecutamos nada con estos datos.
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

                // 7. Construimos una URL de redirección segura para devolver al usuario al post.
                $post_id_to_check = isset($commentdata["comment_post_ID"])
                    ? (int) $commentdata["comment_post_ID"]
                    : 0;
                $redirect_url = "";
                $is_permalink_redirect = false;

                if ($post_id_to_check > 0) {
                    $post_status = get_post_status($post_id_to_check);
                    if (
                        $post_status === "publish" &&
                        comments_open($post_id_to_check)
                    ) {
                        $redirect_url = get_permalink($post_id_to_check);
                        if ($redirect_url) {
                            $is_permalink_redirect = true;
                        }
                    }
                }

                // Fallback seguro si no se pudo obtener la URL del post.
                if (empty($redirect_url)) {
                    $redirect_url = home_url("/");
                }

                $redirect_url = add_query_arg(
                    "sisc_error",
                    $transient_key,
                    $redirect_url
                );

                // Añadimos el ancla solo si estamos seguros de que redirigimos al post original.
                if ($is_permalink_redirect) {
                    $redirect_url .= "#commentform";
                }

                // Mandamiento de Seguridad: Usar las APIs de WordPress. wp_safe_redirect es la forma segura de redirigir.
                wp_safe_redirect($redirect_url);
                exit();
            }

            // 8. Si todo es correcto, devolvemos los datos del comentario para que WordPress continúe.
            return $commentdata;
        }

        /**
         * Verifica si la IP del usuario actual está bloqueada por el lockdown.
         * Es una función auxiliar reutilizable.
         *
         * @return bool True si la IP está bloqueada, false en caso contrario.
         */
        private function _is_ip_locked()
        {
            // Esta comprobación solo es relevante si el lockdown está activado.
            if (empty($this->options["enable_login_lockdown"])) {
                return false;
            }

            // Obtenemos la IP de forma segura usando el método existente.
            $ip = $this->get_user_ip();
            if (empty($ip)) {
                // No podemos determinar el estado sin una IP.
                return false;
            }

            // Construimos la clave del transitorio de bloqueo, igual que en check_ip_lockdown.
            $lock_transient_key = "sisc_ip_lock_" . md5($ip);

            // get_transient() devuelve 'false' si el transitorio no existe o ha expirado.
            // Si devuelve cualquier otro valor, la IP está activamente bloqueada.
            // Mandamiento de Seguridad: Usamos las APIs de WordPress (get_transient) para interactuar
            // con la base de datos de forma segura.
            return (bool) get_transient($lock_transient_key);
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

            // --- INICIO CORRECCIÓN: MITIGACIÓN DE BYPASS DE AUTENTICACIÓN ---
            // La validación del CAPTCHA solo debe saltarse si NO es un intento de login por formulario.
            // Un intento de login por formulario siempre tendrá 'pwd' y 'log' en $_POST.
            $is_form_login_attempt = isset($_POST['log'], $_POST['pwd']);

            if ($is_form_login_attempt) {
                 // Si es un intento de login, los campos del CAPTCHA son OBLIGATORIOS.
                if (!isset($_POST["sisc_transient_key"])) {
                    $this->_record_failed_attempt(); // Se registra el intento fallido de bypass.
                    return new WP_Error(
                        'sisc_missing_fields',
                        __('<strong>ERROR</strong>: CAPTCHA field is missing. Please enable JavaScript or contact the administrator.', 'secure-image-sequence-captcha')
                    );
                }
            } else {
                // No es un intento de login por formulario (ej. auth por cookie), no se necesita CAPTCHA.
                return $user;
            }
            // --- FIN CORRECCIÓN ---
            
            $validation_result = $this->perform_captcha_validation();

            if (is_wp_error($validation_result)) {
                $this->_record_failed_attempt();

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
            // Mandamiento: Verificar si la función está activada.
            if (empty($this->options["enable_register"])) {
                return $errors;
            }

            $validation_result = $this->perform_captcha_validation();
            if (is_wp_error($validation_result)) {
                // Registramos el intento fallido para proteger contra el spam de registro
                // y los ataques de enumeración de usuarios.
                $this->_record_failed_attempt();

                // Mandamiento: Usa las APIs de WordPress.
                // Añadimos el error al objeto $errors estándar de WordPress.
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
