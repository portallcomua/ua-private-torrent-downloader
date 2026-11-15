# UA Private Torrent Downloader 1.0.0

Особистий торрент-завантажувач із WordPress-інтерфейсом.

## 1. Що потрібно

- Linux VPS.
- Docker Engine.
- Docker Compose.
- VPN із підтримкою WireGuard і P2P.
- Домен або піддомен для API.
- WordPress із PHP 7.4 або новішим.

## 2. Структура

ua-private-torrent-downloader/
├── wordpress/
│   └── ua-private-torrent-downloader/
│       └── ua-private-torrent-downloader.php
├── server/
│   ├── app.py
│   ├── Dockerfile
│   └── requirements.txt
├── docker-compose.yml
├── .env.example
├── Caddyfile
├── .gitignore
└── README-UK.md

## 3. Налаштування VPN

Отримай у свого VPN-провайдера WireGuard-конфігурацію.

Потрібні:

- Private Key
- Server Public Key
- Endpoint IP
- Endpoint Port
- VPN Address

Внеси їх у файл .env.

Не використовуй демонстраційні значення.

## 4. Підготовка сервера

Перейди до папки проєкту:

cd ua-private-torrent-downloader

Створи робочу конфігурацію:

cp .env.example .env

Відредагуй:

nano .env

Заповни всі параметри VPN та паролі.

Запусти:

docker compose up -d --build

Перевір:

docker compose ps

Переглянь журнали:

docker compose logs -f vpn torrent api

## 5. Перевірка VPN

Виконай:

docker compose exec api ip link show tun0

Потім:

docker compose exec api ip route get 1.1.1.1

Маршрут повинен проходити через tun0.

Якщо VPN не працює, завантаження не запускай.

Перевір також зовнішню IP-адресу з мережевого простору VPN, перш ніж використовувати систему.

## 6. Налаштування qBittorrent

Після першого запуску отримай тимчасовий пароль:

docker logs uaptd-torrent 2>&1 | grep -i password

Установи власний пароль WebUI.

Він має збігатися з QBIT_PASS у .env.

У налаштуваннях qBittorrent перевір:

- Автоматичне продовження роздачі після завершення.
- Максимальний коефіцієнт роздачі.
- Максимальний час роздачі.
- Пауза після досягнення обмежень.

Це обмежує роздачу після завершення, але не виключає передачу частин файлу пірам під час завантаження.

## 7. HTTPS

Налаштуй DNS піддомена на IP VPS.

У Caddyfile заміни:

torrent-api.example.com

на свій домен.

Розмісти конфігурацію в Caddy і перезапусти його.

API має бути доступний через HTTPS.

Не відкривай порт 8090 безпосередньо в публічний інтернет.

## 8. Встановлення WordPress

1. Відкрий папку wordpress.
2. Заархівуй ua-private-torrent-downloader.
3. У WordPress відкрий Плагіни.
4. Натисни Додати новий.
5. Завантаж ZIP.
6. Активуй плагін.
7. Відкрий меню Private Torrent.
8. Введи HTTPS URL API.
9. Введи API_TOKEN із .env.
10. Збережи налаштування.

## 9. Використання

1. Переконайся, що VPN активний.
2. Встав magnet-посилання.
3. Натисни Завантажити.
4. Дочекайся завершення.
5. Натисни Завантажити файл.

## 10. Безпека

- Не публікуй .env.
- Не публікуй WireGuard Private Key.
- Не передавай API_TOKEN стороннім.
- Використовуй HTTPS.
- Обмеж доступ до сервера через firewall.
- Використовуй сильний пароль WordPress.
- Перевіряй VPN kill switch при відключенні тунелю.

VPN не забезпечує абсолютної анонімності.
VPN-провайдер може бачити метадані з'єднання.
Провайдер VPS має доступ до самого сервера.

BitTorrent передбачає обмін частинами файлів між пірами.
Повністю заборонити такий upload під час отримання файлу
без порушення роботи протоколу неможливо.

Це початкова версія, яку необхідно протестувати
на конкретному сервері та VPN-провайдері.
