# AbeloHost Blog

Блог на чистом PHP, MySQL и Smarty, без фреймворков.

Сейчас реализован первый этап: окружение, автозагрузка, конфигурация, подключение к MySQL, маршрутизация и шаблоны. Главная — временная страница. Схема блога, сидинг, категории и статьи появятся в следующих этапах.

## Требования

- Для Docker-запуска: Docker Engine / Docker Desktop / OrbStack и Docker Compose v2+.
- Для запуска без Docker: PHP 8.1+ с `PDO` и `pdo_mysql`, Composer 2, MySQL 8.x.

В Docker используется PHP 8.3 с Apache и MySQL 8.4. Образы PHP, Composer и MySQL закреплены digest; PHP-зависимости закреплены в `composer.lock`. `config.platform.php` ограничивает зависимости совместимостью с PHP 8.1. Образы и lock-файл следует обновлять осознанно, с повторной проверкой проекта.

## Быстрый старт с Docker

```bash
cp .env.example .env
docker compose up --build -d --wait
docker compose exec --user www-data app php bin/check-db.php
```

Откройте [http://localhost:8080](http://localhost:8080). Успешная проверка БД выводит `MySQL connection OK (utf8mb4, UTC).` Таблиц блога на этом этапе ещё нет.

Если порт занят, измените `APP_PORT` в `.env` и повторите `docker compose up -d --wait`. HTTP-порт доступен только на локальном интерфейсе; порт MySQL наружу не публикуется. Пример паролей предназначен для локальной разработки. Настоящий `.env` не включается в Git и образ приложения.

Исходники и Composer-зависимости копируются в образ при сборке. После изменения PHP, шаблонов или CSS пересоберите приложение:

```bash
docker compose up --build -d --wait
```

На хосте не нужны PHP и Composer. Для более быстрого редактирования без пересборок можно использовать локальный запуск ниже.

Полезные команды:

```bash
docker compose ps
docker compose logs app db
docker compose exec --user www-data app sh -c 'cat var/log/app.log'
docker compose stop
docker compose down
```

Файл `app.log` появляется после первой записанной ошибки. `docker compose down` сохраняет данные. Не добавляйте `-v`, если хотите сохранить БД: этот флаг удаляет volumes. Изменение паролей в `.env` не меняет учётные данные уже инициализированной MySQL — они задаются при первом создании БД.

## Запуск без Docker

Создайте в своей MySQL пустую БД `abelohost` с `utf8mb4` и отдельного пользователя с правами на эту БД. Затем:

```bash
composer install
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_DATABASE=abelohost
export DB_USERNAME=blog
export DB_PASSWORD='your-local-password'
composer check-db
composer serve
```

Приложение доступно на [http://127.0.0.1:8080](http://127.0.0.1:8080). Встроенный сервер PHP предназначен для локальной разработки. При другом порте используйте, например:

```bash
php -d display_errors=0 -S 127.0.0.1:8081 -t public public/router.php
```

PHP читает **переменные окружения**, а не файл `.env`. Docker Compose читает `.env` и передаёт нужные значения контейнерам; при локальном запуске используются команды `export`. В контейнере адрес БД всегда `db:3306`; переменные `DB_HOST`/`DB_PORT` в `.env.example` приведены как справочник для локальной конфигурации.

Для другого Apache/Nginx document root должен указывать на `public/`; запросы к отсутствующим файлам нужно направлять в `public/index.php`. Пользователь PHP должен иметь право создавать/изменять `var/`; не используйте права `777`. В Docker нужные каталоги и права создаются при сборке.

## Проверки

В Docker:

```bash
docker compose exec --user www-data app composer validate --strict
docker compose exec --user www-data app composer lint
docker compose exec --user www-data app composer test
docker compose exec --user www-data app composer test:http -- http://127.0.0.1
docker compose exec --user www-data app composer check-db
```

Без Docker, при запущенном `composer serve`:

```bash
composer validate --strict
composer lint
composer test
composer test:http
composer check-db
```

`composer test` проверяет маршрутизацию, Smarty, экранирование HTML и безопасный ответ 500 при сбое загрузки приложения. Сбой моделируется в отдельном временном каталоге и не меняет рабочие файлы. `composer test:http` проверяет реальные ответы 200/404/405, HEAD без тела, CSS и недоступность внутренних файлов. `composer check-db` выполняет `SELECT 1` через PDO; таблицы и данные не изменяются.

## Структура

```text
bin/check-db.php         проверка подключения к MySQL
config/app.php           конфигурация из окружения
config/bootstrap.php     автозагрузка и инициализация Smarty
docker/                  PHP/Apache image и настройки сервера
public/index.php         вход в приложение
public/router.php        маршрутизатор встроенного PHP-сервера
public/assets/           публичные стили и будущие изображения
src/Controller/          обработчики страниц
src/Http/                маршрутизация и HTTP-ответы
src/Database.php         создание PDO-соединения
src/View.php             настройка Smarty и рендеринг
templates/               общий layout, главная и ошибки
tests/                   проверки каркаса и HTTP
var/                     автоматически создаваемые логи и файлы Smarty
```

Запрос проходит через `public/index.php`, маршрутизатор и контроллер. Контроллер возвращает HTTP-ответ с отрендеренным шаблоном. SQL-репозитории будут добавлены вместе со схемой данных. Соединение с БД создаётся явно через `Database::connect()`, когда оно требуется; главная-заглушка и страницы ошибок не зависят от доступности MySQL.

Smarty экранирует переменные по умолчанию, кэширование HTML отключено. PDO использует `utf8mb4`, исключения и настоящие подготовленные запросы; часовой пояс PHP и сессии MySQL — UTC. Исключения записываются в `var/log/app.log`, а посетителю возвращается общая ошибка без stack trace. Если даже Smarty или Composer недоступны, предусмотрен простой резервный ответ 500.

Документация зависимостей: [Smarty](https://smarty-php.github.io/smarty/stable/), [официальный PHP Docker image](https://hub.docker.com/_/php).
