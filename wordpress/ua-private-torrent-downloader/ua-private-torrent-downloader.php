<?php
/**
 * Plugin Name: UA Private Torrent Downloader
 * Description: Особистий менеджер торрент-завантажень через окремий VPN-сервер.
 * Version: 1.0.0
 * Author: UA Tools
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

final class UAPTD {

    const VERSION = '1.0.0';
    const OPTION = 'uaptd_settings';
    const REST = 'uaptd/v1';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function menu() {
        add_menu_page(
            'UA Private Torrent',
            'Private Torrent',
            'manage_options',
            'uaptd',
            [$this, 'page'],
            'dashicons-download',
            80
        );
    }

    public function register_settings() {
        register_setting('uaptd_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize']
        ]);
    }

    public function sanitize($input) {
        $old = $this->settings();

        return [
            'api_url' => esc_url_raw(
                trim($input['api_url'] ?? '')
            ),
            'api_token' => sanitize_text_field(
                trim($input['api_token'] ?? '')
            ),
            'download_secret' => (
                strlen($input['download_secret'] ?? '') >= 32
                ? sanitize_text_field($input['download_secret'])
                : $old['download_secret']
            )
        ];
    }

    private function settings() {
        return wp_parse_args(
            get_option(self::OPTION, []),
            [
                'api_url' => '',
                'api_token' => '',
                'download_secret' => ''
            ]
        );
    }

    public function assets($hook) {
        if ($hook !== 'toplevel_page_uaptd') return;

        wp_enqueue_style(
            'uaptd-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'uaptd-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            [],
            self::VERSION,
            true
        );

        wp_localize_script('uaptd-admin', 'UAPTD', [
            'root' => esc_url_raw(rest_url(self::REST . '/')),
            'nonce' => wp_create_nonce('wp_rest')
        ]);
    }

    public function routes() {
        register_rest_route(self::REST, '/(?P<path>.*)', [
            'methods' => ['GET', 'POST', 'DELETE'],
            'callback' => [$this, 'api'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);
    }

    private function remote($path, $method = 'GET', $body = null) {
        $s = $this->settings();

        if (!$s['api_url'] || !$s['api_token']) {
            return new WP_Error(
                'uaptd_config',
                'Не налаштовано сервер.',
                ['status' => 400]
            );
        }

        if (strtolower(wp_parse_url($s['api_url'], PHP_URL_SCHEME)) !== 'https') {
            return new WP_Error(
                'uaptd_https',
                'API повинен працювати через HTTPS.',
                ['status' => 400]
            );
        }

        $args = [
            'method' => $method,
            'timeout' => 40,
            'headers' => [
                'Authorization' => 'Bearer ' . $s['api_token'],
                'Content-Type' => 'application/json'
            ]
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request(
            rtrim($s['api_url'], '/') . $path,
            $args
        );

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'uaptd_remote',
                $data['detail'] ?? 'Помилка сервера завантажень.',
                ['status' => 502]
            );
        }

        return $data ?: [];
    }

    public function api(WP_REST_Request $request) {
        $path = trim((string)$request->get_param('path'), '/');
        $method = strtoupper($request->get_method());

        if (strpos($path, '..') !== false) {
            return new WP_Error('uaptd_path', 'Недопустимий шлях.', ['status'=>400]);
        }

        if ($path === 'download-link' && $method === 'POST') {
            return $this->download_link($request);
        }

        $routes = [
            'status' => ['GET', '/api/status'],
            'torrents' => ['GET', '/api/torrents'],
            'add' => ['POST', '/api/add']
        ];

        if (isset($routes[$path]) && $routes[$path][0] === $method) {
            $body = $method === 'POST' ? $request->get_json_params() : null;
            return rest_ensure_response(
                $this->remote($routes[$path][1], $method, $body)
            );
        }

        if ($method === 'DELETE' &&
            preg_match('#^torrents/([a-fA-F0-9]{40})$#', $path, $m)) {

            return rest_ensure_response(
                $this->remote('/api/torrents/' . strtolower($m[1]), 'DELETE')
            );
        }

        return new WP_Error('uaptd_route', 'Невідомий запит.', ['status'=>404]);
    }

    private function download_link(WP_REST_Request $request) {
        $s = $this->settings();
        $data = $request->get_json_params();
        $hash = strtolower(sanitize_text_field($data['hash'] ?? ''));

        if (!preg_match('/^[a-f0-9]{40}$/', $hash)) {
            return new WP_Error('uaptd_hash', 'Невірний hash.', ['status'=>400]);
        }

        if (strlen($s['download_secret']) < 32) {
            return new WP_Error(
                'uaptd_secret',
                'Спочатку налаштуй Download Secret.',
                ['status'=>400]
            );
        }

        $expires = time() + 300;
        $signature = hash_hmac(
            'sha256',
            $hash . '|' . $expires,
            $s['download_secret']
        );

        return rest_ensure_response([
            'url' => rtrim($s['api_url'], '/') .
                '/download/' . $hash .
                '?expires=' . $expires .
                '&signature=' . $signature
        ]);
    }

    public function page() {
        if (!current_user_can('manage_options')) return;

        $s = $this->settings();
        ?>

        <div class="wrap uaptd">

            <h1>UA Private Torrent Downloader</h1>

            <p>Персональний менеджер завантажень. Доступ тільки адміністраторам.</p>

            <section class="uaptd-card">

                <h2>Налаштування сервера</h2>

                <form method="post" action="options.php">

                    <?php settings_fields('uaptd_group'); ?>

                    <label>HTTPS URL API</label>

                    <input
                        type="url"
                        class="large-text"
                        name="<?php echo esc_attr(self::OPTION); ?>[api_url]"
                        value="<?php echo esc_attr($s['api_url']); ?>"
                        placeholder="https://torrent-api.example.com"
                    >

                    <label>API Token</label>

                    <input
                        type="password"
                        class="large-text"
                        autocomplete="new-password"
                        name="<?php echo esc_attr(self::OPTION); ?>[api_token]"
                        value="<?php echo esc_attr($s['api_token']); ?>"
                    >

                    <label>Download Secret (мінімум 32 символи)</label>

                    <input
                        type="password"
                        class="large-text"
                        autocomplete="new-password"
                        name="<?php echo esc_attr(self::OPTION); ?>[download_secret]"
                        value="<?php echo esc_attr($s['download_secret']); ?>"
                    >

                    <?php submit_button('Зберегти налаштування'); ?>

                </form>

            </section>

            <section class="uaptd-card">

                <h2>Захищене з'єднання</h2>

                <div id="uaptd-vpn" class="uaptd-status">
                    Перевірка VPN...
                </div>

                <div id="uaptd-vpn-detail"></div>

            </section>

            <section class="uaptd-card">

                <h2>Нове завантаження</h2>

                <div class="uaptd-add">

                    <input
                        id="uaptd-source"
                        type="text"
                        placeholder="Встав magnet або HTTPS URL .torrent"
                    >

                    <button
                        id="uaptd-add"
                        class="button button-primary"
                        disabled
                    >
                        Додати
                    </button>

                </div>

                <div id="uaptd-message" class="uaptd-status">
                    Очікування...
                </div>

            </section>

            <section class="uaptd-card">

                <div class="uaptd-heading">

                    <h2>Черга завантажень</h2>

                    <button id="uaptd-refresh" class="button">
                        Оновити
                    </button>

                </div>

                <div class="uaptd-table-wrap">

                    <table class="widefat striped">

                        <thead>
                            <tr>
                                <th>Назва</th>
                                <th>Прогрес</th>
                                <th>Швидкість</th>
                                <th>Розмір</th>
                                <th>Дії</th>
                            </tr>
                        </thead>

                        <tbody id="uaptd-list">
                            <tr><td colspan="5">Завантаження...</td></tr>
                        </tbody>

                    </table>

                </div>

            </section>

            <p class="uaptd-note">
                Файли скачуються безпосередньо з окремого сервера через HTTPS.
                Стан VPN не є гарантією абсолютної анонімності.
            </p>

        </div>

        <?php
    }
}

new UAPTD();
