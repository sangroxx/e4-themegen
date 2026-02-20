<?php
/**
 * Plugin Name: TweakCN ACF Brand Sync
 * Description: Paste a TweakCN theme export and sync values into ACF fields under Business Info -> Brand.
 * Version: 0.1.0
 * Author: e4-themegen
 */

if (! defined('ABSPATH')) {
    exit;
}

final class Tweakcn_Acf_Brand_Sync
{
    private const OPTION_RAW_THEME = 'tweakcn_raw_theme_css';
    private const OPTION_LAST_SYNC = 'tweakcn_last_sync';

    /** @var array<string> */
    private array $token_names = [
        'background', 'foreground', 'card', 'card-foreground', 'popover', 'popover-foreground',
        'primary', 'primary-foreground', 'secondary', 'secondary-foreground', 'muted',
        'muted-foreground', 'accent', 'accent-foreground', 'destructive', 'destructive-foreground',
        'border', 'input', 'ring', 'chart-1', 'chart-2', 'chart-3', 'chart-4', 'chart-5',
        'sidebar', 'sidebar-foreground', 'sidebar-primary', 'sidebar-primary-foreground',
        'sidebar-accent', 'sidebar-accent-foreground', 'sidebar-border', 'sidebar-ring',
        'font-sans', 'font-serif', 'font-mono', 'radius', 'shadow-x', 'shadow-y', 'shadow-blur',
        'shadow-spread', 'shadow-opacity', 'shadow-color', 'shadow-2xs', 'shadow-xs', 'shadow-sm',
        'shadow', 'shadow-md', 'shadow-lg', 'shadow-xl', 'shadow-2xl', 'tracking-normal', 'spacing',
    ];

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_action('admin_init', [$this, 'handle_import_submit']);
        add_action('acf/init', [$this, 'register_acf_options_and_fields']);
    }

    public function register_admin_pages(): void
    {
        add_menu_page(
            'Business Info',
            'Business Info',
            'manage_options',
            'business-info',
            '__return_null',
            'dashicons-store',
            59
        );

        add_submenu_page(
            'business-info',
            'Brand',
            'Brand',
            'manage_options',
            'business-info-brand',
            [$this, 'render_import_page']
        );

        add_submenu_page(
            'business-info',
            'TweakCN Import',
            'TweakCN Import',
            'manage_options',
            'tweakcn-import',
            [$this, 'render_import_page']
        );
    }

    public function register_acf_options_and_fields(): void
    {
        if (! function_exists('acf_add_local_field_group') || ! function_exists('acf_add_options_sub_page')) {
            return;
        }

        acf_add_options_sub_page([
            'page_title' => 'Brand',
            'menu_title' => 'Brand',
            'menu_slug'  => 'business-info-brand',
            'parent_slug' => 'business-info',
            'capability' => 'manage_options',
            'position'   => false,
            'autoload'   => true,
        ]);

        $fields = [
            [
                'key' => 'field_tweakcn_raw_theme',
                'label' => 'Raw TweakCN Theme CSS',
                'name' => self::OPTION_RAW_THEME,
                'type' => 'textarea',
                'rows' => 8,
            ],
            [
                'key' => 'field_tweakcn_last_sync',
                'label' => 'Last Sync',
                'name' => self::OPTION_LAST_SYNC,
                'type' => 'text',
                'readonly' => 1,
            ],
        ];

        foreach ($this->token_names as $token) {
            $fields[] = $this->make_field($token, 'root');
            $fields[] = $this->make_field($token, 'dark');
        }

        acf_add_local_field_group([
            'key' => 'group_tweakcn_brand_tokens',
            'title' => 'Brand',
            'fields' => $fields,
            'location' => [
                [
                    [
                        'param' => 'options_page',
                        'operator' => '==',
                        'value' => 'business-info-brand',
                    ],
                ],
            ],
            'position' => 'normal',
            'style' => 'default',
            'active' => true,
        ]);
    }

    /** @return array<string,mixed> */
    private function make_field(string $token, string $mode): array
    {
        $slug = str_replace('-', '_', $token);

        return [
            'key' => 'field_tweakcn_' . $mode . '_' . $slug,
            'label' => strtoupper($mode) . ' ' . $token,
            'name' => 'tweakcn_' . $mode . '_' . $slug,
            'type' => 'text',
        ];
    }

    public function render_import_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $raw_css = (string) get_option(self::OPTION_RAW_THEME, '');

        echo '<div class="wrap">';
        echo '<h1>TweakCN Theme Import</h1>';
        echo '<p>Paste your full TweakCN export from <code>tweakcn.com/editor/theme</code>. The importer validates the expected format, then updates Business Info → Brand ACF fields.</p>';

        if (isset($_GET['tweakcn_import']) && $_GET['tweakcn_import'] === 'success') {
            echo '<div class="notice notice-success"><p>Theme imported and ACF fields updated.</p></div>';
        }

        if (isset($_GET['tweakcn_error'])) {
            $error = sanitize_text_field(wp_unslash((string) $_GET['tweakcn_error']));
            echo '<div class="notice notice-error"><p><strong>Import failed:</strong> ' . esc_html($error) . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=tweakcn-import')) . '">';
        wp_nonce_field('tweakcn_import_css', 'tweakcn_import_nonce');
        echo '<input type="hidden" name="tweakcn_action" value="import" />';
        echo '<textarea name="tweakcn_css" rows="24" style="width: 100%; font-family: monospace;">' . esc_textarea($raw_css) . '</textarea>';
        submit_button('Import TweakCN Theme');
        echo '</form>';
        echo '</div>';
    }

    public function handle_import_submit(): void
    {
        if (! is_admin() || ! current_user_can('manage_options')) {
            return;
        }

        if (! isset($_POST['tweakcn_action']) || $_POST['tweakcn_action'] !== 'import') {
            return;
        }

        check_admin_referer('tweakcn_import_css', 'tweakcn_import_nonce');

        $raw_css = isset($_POST['tweakcn_css']) ? (string) wp_unslash($_POST['tweakcn_css']) : '';
        $parsed = $this->parse_theme_css($raw_css);

        if (isset($parsed['error'])) {
            $this->redirect_with_error((string) $parsed['error']);
        }

        update_option(self::OPTION_RAW_THEME, $raw_css, false);
        update_option(self::OPTION_LAST_SYNC, gmdate('c'), false);

        foreach (['root', 'dark'] as $mode) {
            foreach ($this->token_names as $token) {
                $slug = str_replace('-', '_', $token);
                $value = $parsed[$mode][$token] ?? '';
                update_option('tweakcn_' . $mode . '_' . $slug, $value, false);
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=tweakcn-import&tweakcn_import=success'));
        exit;
    }

    /**
     * @return array<string,mixed>
     */
    private function parse_theme_css(string $css): array
    {
        $trimmed = trim($css);
        if ($trimmed === '') {
            return ['error' => 'The pasted theme is empty.'];
        }

        if (! preg_match('/@theme\s+inline\s*\{/', $trimmed)) {
            return ['error' => 'Missing required `@theme inline { ... }` block from TweakCN export.'];
        }

        $root_block = $this->extract_css_block($trimmed, ':root');
        if ($root_block === null) {
            return ['error' => 'Could not find the `:root { ... }` block.'];
        }

        $dark_block = $this->extract_css_block($trimmed, '.dark');
        if ($dark_block === null) {
            return ['error' => 'Could not find the `.dark { ... }` block.'];
        }

        $root_values = $this->extract_variables($root_block);
        $dark_values = $this->extract_variables($dark_block);

        $missing = [];
        foreach ($this->token_names as $token) {
            if (! isset($root_values[$token])) {
                $missing[] = ':root --' . $token;
            }
            if (! isset($dark_values[$token])) {
                $missing[] = '.dark --' . $token;
            }
        }

        if (! empty($missing)) {
            return ['error' => 'Theme pattern mismatch. Missing required variables: ' . implode(', ', $missing)];
        }

        return [
            'root' => $root_values,
            'dark' => $dark_values,
        ];
    }

    private function extract_css_block(string $css, string $selector): ?string
    {
        $start = strpos($css, $selector);
        if ($start === false) {
            return null;
        }

        $open = strpos($css, '{', $start);
        if ($open === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($css);
        for ($i = $open; $i < $length; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($css, $open + 1, $i - $open - 1);
                }
            }
        }

        return null;
    }

    /** @return array<string,string> */
    private function extract_variables(string $block): array
    {
        $vars = [];
        if (preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/i', $block, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $vars[strtolower(trim($match[1]))] = trim($match[2]);
            }
        }

        return $vars;
    }

    private function redirect_with_error(string $message): void
    {
        wp_safe_redirect(admin_url('admin.php?page=tweakcn-import&tweakcn_error=' . rawurlencode($message)));
        exit;
    }
}

$plugin = new Tweakcn_Acf_Brand_Sync();
$plugin->boot();
