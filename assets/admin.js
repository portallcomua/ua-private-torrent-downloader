(function () {
    'use strict';

    const $ = selector => document.querySelector(selector);

    async function api(path, method = 'GET', data = null) {

        const options = {
            method,
            headers: {
                'X-WP-Nonce': UAPTD.nonce,
                'Content-Type': 'application/json'
            }
        };

        if (data) {
            options.body = JSON.stringify(data);
        }

        const response = await fetch(
            UAPTD.root + path,
            options
        );

        const result = await response.json().catch(
            () => ({ message: 'Помилка відповіді сервера' })
        );

        if (!response.ok) {
            throw new Error(
                result.message ||
                result.code ||
                result.detail ||
                'Помилка API'
            );
        }

        return result;
    }

    function message(text, good = false) {

        const element = $('#uaptd-message');

        element.textContent = text;

        element.className =
            'uaptd-status ' +
            (good ? 'uaptd-good' : 'uaptd-bad');
    }

    function escapeHTML(value) {

        return String(value ?? '').replace(
            /[&<>"']/g,
            char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            })[char]
        );
    }

    async function checkVPN() {

        try {

            const result = await api('status');

            const box = $('#uaptd-vpn');

            if (result.vpn_ok) {

                box.textContent = 'VPN: активний';

                box.className =
                    'uaptd-status uaptd-good';

            } else {

                box.textContent =
                    'VPN НЕ ПІДТВЕРДЖЕНО';

                box.className =
                    'uaptd-status uaptd-bad';
            }

            $('#uaptd-vpn-detail').textContent =
                result.vpn_detail || '';

            $('#uaptd-add').disabled =
                !result.vpn_ok;

        } catch (error) {

            $('#uaptd-vpn').textContent =
                'Сервер недоступний';

            $('#uaptd-vpn').className =
                'uaptd-status uaptd-bad';

            $('#uaptd-vpn-detail').textContent =
                error.message;

            $('#uaptd-add').disabled = true;
        }
    }

    async function loadTorrents() {

        try {

            const result = await api('torrents');

            const tbody = $('#uaptd-list');

            if (!result.items || !result.items.length) {

                tbody.innerHTML =
                    '<tr><td colspan="5">Черга порожня</td></tr>';

                return;
            }

            tbody.innerHTML = result.items.map(t => {

                const progress = Math.max(
                    0,
                    Math.min(100, Number(t.progress) * 100)
                );

                let buttons = '';

                if (t.completed) {

                    buttons += `
                        <button
                            class="button button-primary uaptd-download"
                            data-hash="${escapeHTML(t.hash)}">
                            Завантажити
                        </button>
                    `;
                }

                buttons += `
                    <button
                        class="button uaptd-delete"
                        data-hash="${escapeHTML(t.hash)}">
                        Видалити
                    </button>
                `;

                return `
                    <tr>
                        <td>
                            <strong>${escapeHTML(t.name)}</strong>
                            <br>
                            <small>${escapeHTML(t.state)}</small>
                        </td>

                        <td>
                            <div class="uaptd-progress">
                                <span style="width:${progress}%"></span>
                            </div>
                            ${progress.toFixed(1)}%
                        </td>

                        <td>${escapeHTML(t.download_speed)}</td>

                        <td>${escapeHTML(t.size)}</td>

                        <td>
                            <div class="uaptd-actions">
                                ${buttons}
                            </div>
                        </td>
                    </tr>
                `;

            }).join('');

        } catch (error) {

            message(error.message);
        }
    }

    async function refresh() {

        await checkVPN();

        await loadTorrents();
    }

    $('#uaptd-add').addEventListener('click', async () => {

        const source = $('#uaptd-source').value.trim();

        if (!source) {
            message('Встав посилання.');
            return;
        }

        try {

            await api('add', 'POST', {
                source
            });

            $('#uaptd-source').value = '';

            message('Завантаження додано.', true);

            await refresh();

        } catch (error) {

            message(error.message);
        }
    });

    $('#uaptd-refresh').addEventListener(
        'click',
        refresh
    );

    $('#uaptd-list').addEventListener('click', async event => {

        const download = event.target.closest('.uaptd-download');

        const remove = event.target.closest('.uaptd-delete');

        if (download) {

            try {

                const result = await api('download-link', 'POST', {
                    hash: download.dataset.hash
                });

                window.location.href = result.url;

            } catch (error) {

                message(error.message);
            }
        }

        if (remove) {

            if (!confirm('Видалити торрент і файли?')) {
                return;
            }

            try {

                await api(
                    'torrents/' + remove.dataset.hash,
                    'DELETE'
                );

                message('Видалено.', true);

                await refresh();

            } catch (error) {

                message(error.message);
            }
        }
    });

    refresh();

    setInterval(refresh, 10000);

})();
