# Прокси к AlfaCRM

Серверный посредник между финмоделью (публичный `index.html`) и API AlfaCRM.
Нужен, потому что из браузера к Alfa обращаться нельзя: API-ключ утечёт в публичном
коде + CORS. Ключ лежит **только здесь, на сервере**, в `config.php` (в git его нет).

## Как это работает

1. Клиент (модель) после входа в Firebase получает ID-токен и шлёт его в заголовке
   `Authorization: Bearer <token>`.
2. Прокси проверяет токен по публичным сертификатам Google (значит человек реально
   вошёл в наш Firebase-проект) и сверяет email со списком `allowed_emails`.
3. Прокси авторизуется в Alfa (email + api_key), кэширует токен Alfa (~50 мин) и
   ходит в API Alfa от своего имени.

## Эндпоинты

Адрес на хостинге: `https://app.proznanie.club/finmodel/api/alfa/`

| Запрос | Что делает |
|---|---|
| `GET  ?action=ping` | Проверка: токен ок, конфиг на месте. Вернёт ваш email и хост Alfa. |
| `GET  ?action=branches` | Список филиалов Alfa с их `id` — чтобы узнать `branch`. |
| `POST ?action=customers` | Ученики/клиенты Alfa: `id`, ФИО, телефоны. Тело `{filter:{...}}` необязательно. |
| `GET  ?action=refs` | Справочники для маппинга модель→Alfa: subjects, rooms, lesson-types (+ мягко teachers/statuses). Для этапа 3 (публикация групп). |
| `POST ?action=publish` | Публикация ОДНОЙ группы (создать группу+расписание+состав). **По умолчанию `dryRun:true`** — только показывает payload, ничего не создаёт. Живое создание — тело `{"dryRun":false,...}`. См. `PUBLISH_GROUPS.md`. |

Все запросы требуют заголовок `Authorization: Bearer <firebase_id_token>`.

## Установка на хостинге (делает Жанна)

1. Через файловый менеджер HostFly зайти в папку `finmodel/api/alfa/`
   (появится после первой автозаливки — `git push`).
2. Скопировать `config.example.php` → `config.php`.
3. Открыть `config.php`, вписать `email` и `api_key` пользователя AlfaCRM
   (Alfa → Настройки → Права доступа → API-ключ). Сохранить.
4. Проверить в браузере, войдя в модель, что `…/api/alfa/?action=branches`
   возвращает список филиалов — там будет нужный `branch` id. Вписать его в `config.php`.

`config.php` в git не попадает и автозаливкой не перезаписывается (он в `.gitignore`
и в `exclude` GitHub Actions).

## ⚠️ Защита `config.php` от чтения по URL

`config.php` содержит ключ Alfa, и его нельзя отдавать по прямой ссылке. Сейчас его
прячет `.htaccess` (`Require all denied` + `Options -Indexes`). **Это работает только
на Apache/LiteSpeed, читающих `.htaccess`.** HostFly (shared) — Apache, поэтому ок.

Если прокси когда-нибудь переедет на **nginx**, `.htaccess` игнорируется и
`GET …/api/alfa/config.php` вернёт исходник с ключом. Тогда обязательно добавить в
конфиг nginx (в `server`/`location` сайта):

```nginx
location ~ /api/alfa/config\.php$ { deny all; return 404; }
```

Более надёжный вариант при любом сервере — хранить `config.php` ВЫШЕ корня сайта
и подключать `require __DIR__.'/../../config.php';`. Пока (Apache) не требуется.

## Требования сервера

PHP 8.0+, расширения `curl`, `openssl`, `json` (стандартные). Исходящий HTTPS
(нужен для сертификатов Google и API Alfa).
