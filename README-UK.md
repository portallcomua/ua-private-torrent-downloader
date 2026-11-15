# UA Private Torrent Downloader PRO 1.0.0

Персональна система керування торрент-завантаженнями.

## Архітектура

1. WordPress — інтерфейс керування.
2. Linux VPS — сервер завантаження.
3. Gluetun — VPN та мережевий firewall.
4. WireGuard — VPN-тунель.
5. qBittorrent — торрент-двигун.
6. FastAPI — зв'язок між WordPress і qBittorrent.
7. Caddy — HTTPS.

## Вимоги

- Linux VPS.
- Docker Engine.
- Docker Compose.
- Доступ до WireGuard-конфігурації VPN.
- VPN-провайдер, який дозволяє P2P.
- Домен для API.
- WordPress із PHP 7.4+.

## Підготовка

Створи робочу папку:

mkdir ua-private-torrent-downloader

Перейди до неї:

cd ua-private-torrent-downloader

Створи .env:

cp .env.example .env

Відредагуй:

nano .env

Внеси справжні параметри WireGuard.

Згенеруй API_TOKEN:

openssl rand -hex 32

Згенеруй DOWNLOAD_SECRET:

openssl rand -hex 32

Створи сильний пароль qBittorrent.

Не використовуй тестові значення.

## Запуск

docker compose up -d --build

Перевір контейнери:

docker compose ps

Переглянь журнали:

docker compose logs -f vpn torrent api

## Перевірка VPN

Перевір інтерфейс:

docker compose exec api ip link show tun0

Перевір маршрут:

docker compose exec api ip route get 1.1.1.1

Переконайся, що маршрут проходить через tun0.

Після цього перевір зовнішню IP-адресу з контейнера.

Не починай завантаження, поки VPN не перевірено.

## Перевірка qBittorrent

WebUI доступний тільки локально на VPS:

http://127.0.0.1:8080

Для доступу зі свого ПК створи SSH-тунель:

ssh -L 8080:127.0.0.1:8080 USER@SERVER_IP

Відкрий:

http://127.0.0.1:8080

Використовуй облікові дані з .env.

## HTTPS

Налаштуй DNS піддомена на IP VPS.

Налаштуй Caddyfile.

Переконайся, що API доступний через HTTPS.

## WordPress

1. Відкрий папку wordpress.
2. Заархівуй папку ua-private-torrent-downloader.
3. Відкрий WordPress.
4. Плагіни → Додати новий.
5. Завантаж ZIP.
6. Активуй.
7. Відкрий Private Torrent.

Заповни:

HTTPS URL API
API Token
Download Secret

Download Secret має збігатися з параметром DOWNLOAD_SECRET у .env.

## Використання

1. Встав Magnet-посилання або HTTPS URL .torrent.
2. Натисни Додати.
3. Дочекайся завершення.
4. Натисни Завантажити.
5. Файл передається напряму із сервера на твій пристрій.

## Автоматизація

Система перевіряє VPN перед основними операціями.

Фоновий процес перевіряє торрент-чергу кожні три секунди.

Після завершення торрент автоматично ставиться на паузу.

Завантажені файли залишаються на VPS, поки ти не видалиш їх.

## Важливо

Автоматична пауза після завершення не гарантує нульової
віддачі під час завантаження.

VPN не забезпечує абсолютної анонімності.

VPN-провайдер може бачити метадані з'єднання.

Провайдер VPS має доступ до самого сервера.

Не використовуй цю систему для обходу авторизації,
порушення прав власників файлів або інших незаконних дій.

Перед використанням перевір firewall, VPN kill switch,
маршрутизацію та доступність API.
