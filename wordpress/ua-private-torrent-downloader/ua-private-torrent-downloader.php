<?php
/**
 * Plugin Name: UA Private Torrent Downloader
 * Description: Особистий торрент-завантажувач через окремий VPN-сервер.
 * Version: 1.0.0
 * Author: UA Tools
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

final class UAPTD_Plugin {

    const OPT = 'uaptd_settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function menu() {
        add_menu_page(
            'UA Private Torrent Downloader',
            'Private Torrent',
            'manage_options',
            'ua-private-torrent',
            [$this, 'page'],
            'dashicons-download',
            80
        );
    }

    public function settings() {
        register_setting('uaptd_group', self::OPT, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize']
        ]);
    }

    public function sanitize($input) {
        return [
            'api_url' => esc_url_raw(
                trim($input['api_url'] ?? '')
            ),
            'api_token' => sanitize_text_field(
                trim($input['api_token'] ?? '')
            )
        ];
    }

    private function config() {
        return wp_parse_args(
            get_option(self::OPT, []),
            [
                'api_url' => '',
                'api_token' => ''
            ]
        );
    }

    public function assets($hook) {
        if ($hook !== 'toplevel_page_ua-private-torrent') {
            return;
        }

        wp_register_style('uaptd-style', false);
        wp_enqueue_style('uaptd-style');

        wp_add_inline_style('uaptd-style', '
            .uaptd-wrap{max-width:1100px}
            .uaptd-panel{
                background:#fff;
                border:1px solid #ddd;
                border-radius:10px;
                padding:20px;
                margin:18px 0
            }
            .uaptd-row{
                display:flex;
                gap:10px;
                flex-wrap:wrap;
                align-items:center
            }
            .uaptd-row input{
                min-width:250px;
                flex:1
            }
            .uaptd-status{
                padding:12px;
                margin:12px 0;
                border-radius:7px;
                background:#f0f0f1
            }
            .uaptd-good{
                background:#e8f8ed;
                color:#17632c
            }
            .uaptd-bad{
                background:#fceaea;
                color:#9a2020
            }
            .uaptd-progress{
                height:10px;
                background:#ddd;
                border-radius:8px;
                overflow:hidden
            }
            .uaptd-progress span{
                display:block;
                height:100%;
                background:#2271b1
            }
            .uaptd-table{
                width:100%;
                border-collapse:collapse
            }
            .uaptd-table td,
            .uaptd-table th{
                padding:10px;
                border-bottom:1px solid #eee;
                text-align:left
            }
            .uaptd-small{
                font-size:12px;
                color:#777
            }
        ');

        wp_register_script('uaptd-script', false, [], '1.0.0', true);
        wp_enqueue_script('uaptd-script');

        wp_localize_script('uaptd-script', 'UAPTD', [
            'root' => esc_url_raw(rest_url('uaptd/v1/')),
            'nonce' => wp_create_nonce('wp_rest')
        ]);

        wp_add_inline_script('uaptd-script', $this->js());
    }

    private function js() {
        return <<<'JS'
(function(){
'use strict';

const $ = s => document.querySelector(s);

async function api(path, method='GET', data=null) {

    const options = {
        method: method,
        headers: {
            'X-WP-Nonce': UAPTD.nonce,
            'Content-Type': 'application/json'
        }
    };

    if(data) {
        options.body = JSON.stringify(data);
    }

    const response = await fetch(
        UAPTD.root + path,
        options
    );

    const result = await response.json().catch(
        () => ({detail:'Помилка відповіді сервера'})
    );

    if(!response.ok) {
        throw new Error(
            result.detail ||
            result.message ||
            'Помилка запиту'
        );
    }

    return result;
}

function message(text, good=false) {
    const box = $('#uaptd-message');
    box.textContent = text;
    box.className = 'uaptd-status ' +
        (good ? 'uaptd-good' : 'uaptd-bad');
}

function escapeHTML(value) {
    return String(value ?? '').replace(
        /[&<>"']/g,
        c => ({
            '&':'&amp;',
            '<':'&lt;',
            '>':'&gt;',
            '"':'&quot;',
            "'":'&#39;'
        }[c])
    );
}

async function checkVPN() {

    try {

        const result = await api('status');

        const box = $('#uaptd-vpn');

        if(result.vpn_ok) {

            box.textContent = 'VPN: активний';
            box.className = 'uaptd-status uaptd-good';

        } else {

            box.textContent = 'VPN НЕ ПІДТВЕРДЖЕНО';
            box.className = 'uaptd-status uaptd-bad';
        }

        $('#uaptd-vpn-detail').textContent =
            result.vpn_detail || '';

        $('#uaptd-add').disabled = !result.vpn_ok;

    } catch(error) {

        $('#uaptd-vpn').textContent =
            'Сервер недоступний: ' + error.message;

        $('#uaptd-vpn').className =
            'uaptd-status uaptd-bad';

        $('#uaptd-add').disabled = true;
    }
}

async function loadTorrents() {

    try {

        const result = await api('torrents');
        const tbody = $('#uaptd-list');

        if(!result.items || !result.items.length) {

            tbody.innerHTML =
                '<tr><td colspan="5">Завантажень поки немає</td></tr>';

            return;
        }

        tbody.innerHTML = result.items.map(torrent => {

            const progress = Math.max(
                0,
                Math.min(100, Number(torrent.progress || 0) * 100)
            );

            let buttons = '';

            if(torrent.state === 'completed') {

                buttons +=
                    '<a class="button button-primary" href="' +
                    UAPTD.root + 'download/' +
                    encodeURIComponent(torrent.hash) +
                    '?_wpnonce=' +
                    encodeURIComponent(UAPTD.nonce) +
                    '">Завантажити файл</a> ';
            }

            buttons +=
                '<button class="button uaptd-delete" data-hash="' +
                escapeHTML(torrent.hash) +
                '">Видалити</button>';

            return `
                <tr>
                    <td>
                        <strong>${escapeHTML(torrent.name)}</strong>
                        <div class="uaptd-small">
                            ${escapeHTML(torrent.state_text)}
                        </div>
                    </td>
                    <td>
                        <div class="uaptd-progress">
                            <span style="width:${progress}%"></span>
                        </div>
                        ${progress.toFixed(1)}%
                    </td>
                    <td>${escapeHTML(torrent.dlspeed_text)}</td>
                    <td>${escapeHTML(torrent.size_text)}</td>
                    <td>${buttons}</td>
                </tr>
            `;

        }).join('');

    } catch(error) {

        message('Помилка списку: ' + error.message);
    }
}

async function refresh() {
    await checkVPN();
    await loadTorrents();
}

$('#uaptd-add').addEventListener('click', async function(){

    const source = $('#uaptd-magnet').value.trim();

    if(!source) {
        message('Встав magnet або HTTPS-посилання на .torrent');
        return;
    }

    try {

        await api('add', 'POST', {
            source: source
        });

        $('#uaptd-magnet').value = '';

        message('Завдання додано.', true);

        await refresh();

    } catch(error) {

        message(error.message);
    }
});

$('#uaptd-refresh').addEventListener(
    'click',
    refresh
);

$('#uaptd-list').addEventListener('click', async function(event){

    const button = event.target.closest('.uaptd-delete');

    if(!button) return;

    if(!confirm('Видалити торрент і всі його завантажені файли?')) {
        return;
    }

    try {

        await api(
            'torrents/' + encodeURIComponent(button.dataset.hash),
            'DELETE'
        );

        message('Видалено.', true);

        await refresh();

    } catch(error) {

        message(error.message);
    }
});

refresh();

setInterval(refresh, 10000);

})();
JS;
    }

    public function routes() {

        register_rest_route('uaptd/v1', '/(?P<path>.*)', [
            'methods' => ['GET', 'POST', 'DELETE'],
            'callback' => [$this, 'proxy'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);
    }

    public function proxy(WP_REST_Request $request) {

        $config = $this->config();

        if(empty($config['api_url']) || empty($config['api_token'])) {

            return new WP_Error(
                'uaptd_unconfigured',
                'Спочатку налаштуй сервер.',
                ['status' => 400]
            );
        }

        $path = trim(
            (string)$request->get_param('path'),
            '/'
        );

        if(strpos($path, '..') !== false) {
            return new WP_Error('uaptd_path', 'Недопустимий шлях.', ['status'=>400]);
        }

        $method = strtoupper($request->get_method());

        /*
         * Завантаження готового файлу.
         */

        if(strpos($path, 'download/') === 0 && $method === 'GET') {

            $hash = substr($path, 9);

            if(!preg_match('/^[a-fA-F0-9]{40}$/', $hash)) {
                return new WP_Error('uaptd_hash', 'Невірний hash.', ['status'=>400]);
            }

            $temp = wp_tempnam('uaptd');

            if(!$temp) {
                return new WP_Error('uaptd_temp', 'Не вдалося створити тимчасовий файл.', ['status'=>500]);
            }

            $response = wp_remote_get(
                rtrim($config['api_url'], '/') .
                '/api/download/' . rawurlencode($hash),
                [
                    'timeout' => 600,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $config['api_token']
                    ],
                    'stream' => true,
                    'filename' => $temp
                ]
            );

            if(is_wp_error($response)) {
                @unlink($temp);
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);

            if($code !== 200 || !file_exists($temp)) {
                @unlink($temp);

                return new WP_Error(
                    'uaptd_download',
                    'Файл ще не готовий або сервер повернув помилку.',
                    ['status'=>502]
                );
            }

            $disposition = wp_remote_retrieve_header(
                $response,
                'content-disposition'
            );

            $filename = 'download.bin';

            if($disposition && preg_match('/filename="?([^";]+)"?/i', $disposition, $match)) {
                $filename = sanitize_file_name($match[1]);
            }

            nocache_headers();

            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($temp));

            readfile($temp);

            @unlink($temp);

            exit;
        }

        /*
         * Дозволені API-операції.
         */

        if(
            $method === 'DELETE' &&
            preg_match('#^torrents/[a-fA-F0-9]{40}$#', $path)
        ) {

            $remote_path = '/api/' . $path;

        } elseif(
            in_array($path, ['status', 'torrents', 'add'], true) &&
            in_array($method, ['GET', 'POST'], true)
        ) {

            $remote_path = '/api/' . $path;

        } else {

            return new WP_Error(
                'uaptd_route',
                'Невідомий запит.',
                ['status'=>404]
            );
        }

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $config['api_token'],
                'Content-Type' => 'application/json'
            ]
        ];

        if($method === 'POST') {
            $args['body'] = wp_json_encode(
                $request->get_json_params()
            );
        }

        $response = wp_remote_request(
            rtrim($config['api_url'], '/') . $remote_path,
            $args
        );

        if(is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);

        $data = json_decode(
            wp_remote_retrieve_body($response),
            true
        );

        if($code < 200 || $code >= 300) {

            return new WP_Error(
                'uaptd_remote',
                $data['detail'] ?? 'Помилка сервера.',
                ['status'=>502]
            );
        }

        return rest_ensure_response($data);
    }

    public function page() {

        if(!current_user_can('manage_options')) {
            return;
        }

        $config = $this->config();

        ?>

        <div class="wrap uaptd-wrap">

            <h1>UA Private Torrent Downloader</h1>

            <p>
                Особистий центр торрент-завантажень.
                Доступ тільки адміністратору.
            </p>

            <div class="uaptd-panel">

                <h2>1. Налаштування сервера</h2>

                <form method="post" action="options.php">

                    <?php settings_fields('uaptd_group'); ?>

                    <p>
                        <strong>HTTPS URL API</strong><br>

                        <input
                            type="url"
                            class="regular-text"
                            name="<?php echo esc_attr(self::OPT); ?>[api_url]"
                            value="<?php echo esc_attr($config['api_url']); ?>"
                            placeholder="https://torrent-api.example.com"
                        >
                    </p>

                    <p>
                        <strong>Секретний API-токен</strong><br>

                        <input
                            type="password"
                            class="regular-text"
                            autocomplete="new-password"
                            name="<?php echo esc_attr(self::OPT); ?>[api_token]"
                            value="<?php echo esc_attr($config['api_token']); ?>"
                        >
                    </p>

                    <?php submit_button('Зберегти налаштування'); ?>

                </form>

                <p class="uaptd-small">
                    Використовуй тільки HTTPS. Не вставляй сюди
                    WireGuard-приватний ключ: він зберігається
                    лише на окремому сервері.
                </p>

            </div>

            <div class="uaptd-panel">

                <h2>2. Стан VPN</h2>

                <div id="uaptd-vpn" class="uaptd-status">
                    Перевірка...
                </div>

                <div id="uaptd-vpn-detail" class="uaptd-small"></div>

            </div>

            <div class="uaptd-panel">

                <h2>3. Нове завантаження</h2>

                <div class="uaptd-row">

                    <input
                        type="text"
                        id="uaptd-magnet"
                        placeholder="Встав magnet або HTTPS URL .torrent"
                    >

                    <button
                        id="uaptd-add"
                        class="button button-primary"
                        disabled
                    >
                        Завантажити
                    </button>

                </div>

                <div id="uaptd-message" class="uaptd-status">
                    Очікування...
                </div>

                <p class="uaptd-small">
                    Додавай тільки файли, які маєш право завантажувати.
                    Підтримуються magnet-посилання та прямі HTTPS URL
                    файлів .torrent.
                </p>

            </div>

            <div class="uaptd-panel">

                <div class="uaptd-row">

                    <h2 style="margin:0">4. Мої завантаження</h2>

                    <button id="uaptd-refresh" class="button">
                        Оновити
                    </button>

                </div>

                <table class="uaptd-table">

                    <thead>
                        <tr>
                            <th>Назва / стан</th>
                            <th>Прогрес</th>
                            <th>Швидкість</th>
                            <th>Розмір</th>
                            <th>Дії</th>
                        </tr>
                    </thead>

                    <tbody id="uaptd-list">

                        <tr>
                            <td colspan="5">Завантаження...</td>
                        </tr>

                    </tbody>

                </table>

            </div>

        </div>

        <?php
    }
}

new UAPTD_Plugin();
