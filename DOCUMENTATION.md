# Финмодель «Прознание + CODDY» — документация проекта и план миграции

> Документ для Claude Code. Цель: перенести систему с GitHub Pages + Google Apps Script на белорусский shared-хостинг (PHP + MySQL), сохранив всю функциональность и данные.

---

## 1. ЧТО ЭТО ЗА СИСТЕМА

Интерактивная финансовая модель детского образовательного центра (клуб «Прознание» + школа программирования CODDY, Могилёв, Республика Беларусь).

Внутренний инструмент директора для управления:
- каталог курсов (цена, ставка ЗП педагога, наполняемость групп),
- расписание занятий (сетка: день × время × кабинет),
- педсостав и фонд оплаты труда,
- постоянные расходы,
- налоги РБ (УСН 6%, ФСЗН 34%, Белгосстрах 0,6%),
- точка безубыточности, планирование набора, сценарии «что если».

**Пользователь:** один человек (директор), работает с 2–3 компьютеров. Многопользовательский доступ не требуется, но данные должны быть одинаковыми на всех устройствах.

**Язык интерфейса:** русский. Валюта: белорусский рубль.

---

## 2. ТЕКУЩАЯ АРХИТЕКТУРА (что переносим)

### 2.1 Клиент
- **Один файл** `index.html` (~230 КБ) — весь интерфейс, стили и логика в одном файле (HTML + CSS + JS, без сборки, без фреймворков).
- Размещён на **GitHub Pages**: `https://jane3dmp.github.io/finmodel-club/`
- Репозиторий: `jane3dmp/finmodel-club`
- Внешние зависимости: только SheetJS (xlsx) с CDN, подгружается по требованию для импорта/экспорта Excel.

### 2.2 Хранилище (текущее — источник проблем)
Данные хранятся **в двух местах одновременно**:

1. **localStorage браузера** — локальная копия на каждом компьютере.
   - Ключ `proznanie_finmodel_v1` — текущее состояние модели.
   - Ключ `proznanie_finmodel_scenarios_v1` — список всех версий модели.

2. **Google-таблица через Apps Script** — «облако» для синхронизации между компьютерами.
   - URL веб-приложения: `https://script.google.com/macros/s/AKfycbxJajbntVctGjXImc1hVhWimvOkXHcNCkuVFpszEbA8ad-B40Lj2Q8gdMMTBar4YLnsew/exec`
   - Лист `DB`: строка 1 — служебная (A1 = имя текущей версии, B1 = метка времени). Со строки 2: колонка A — имя версии, колонки B, C, D… — JSON версии, **разбитый на куски по 40 000 символов** (обход лимита Google Sheets в 50 000 символов на ячейку).
   - Листы-витрины (по 5 на версию): `{версия} · Курсы`, `· Расписание`, `· Педсостав`, `· Расходы`, `· Настройки` — человекочитаемое представление, только для просмотра.

### 2.3 Проблемы текущей архитектуры (причина миграции)
Все проблемы **реальны и воспроизводились**, не гипотетические:

| Проблема | Проявление |
|---|---|
| Медленный ответ Apps Script | Загрузка модели 30–40 секунд, иногда таймаут |
| Лимит ячейки Google Sheets (50 000 симв.) | Версия с полным расписанием не влезала → Google молча обрезал → битый JSON |
| Apps Script отвечает `text/html` вместо JSON | `response.json()` падал → ложная «Ошибка связи» |
| Порча данных | В таблице оказывались обрывки вида `{"courses":[{"nar 1Z"}]}` |
| Хрупкая синхронизация между компьютерами | Старый компьютер перезаписывал свежие данные |
| Нет надёжного 24/7 | Зависимость от доступности Google, квот Apps Script |

---

## 3. МОДЕЛЬ ДАННЫХ

Всё состояние приложения — один JS-объект `S`. Ниже его структура (это то, что надо разложить в таблицы MySQL).

### 3.1 Корневые поля `S`

```javascript
S = {
  courses: [...],      // массив курсов
  plan: [...],         // массив плана набора (планировщик загрузки)
  grid: [...],         // массив занятий (расписание)
  staff: [...],        // массив педагогов
  wageTable: {...},    // справочник ставок ЗП по курсам
  fixed: {...},        // постоянные расходы: {название: сумма}
  fixedGroup: {...},   // группа расхода: {название: 1|2}  (1=общие, 2=админ-команда)
  fixedPct: [...],     // расходы в % от оборота: [{name, pct}]
  tax: {...},          // ставки налогов
  assume: {...},       // допущения модели
  funnel: {...},       // воронка набора
  _wageMode: "kpi",    // модель ЗП: "kpi" | "fix"
  _fixRates: {...},    // фикс-ставки: {педагог: {длительность_мин: ставка}}
  _npdMonth: 0,        // ФОТ на НПД (самозанятые), не облагается взносами
  meta: {name: "..."}, // имя текущей версии
  _savedAt: "ISO"      // метка времени последней выгрузки в облако
}
```

### 3.2 Курс (`S.courses[]`)

```javascript
{
  name: "Английский язык",   // название (ключ, уникально)
  eco: "Прознание",          // экосистема: "Прознание" | "CODDY" | "Детали"
  price: 28,                 // цена за занятие, руб
  single: 26,                // цена разового занятия
  wage: 40,                  // базовая ставка ЗП педагога за занятие
  dur: 1,                    // длительность занятия, часов (1 | 1.5 | 2)
  groupSize: 8,              // целевой размер группы
  groups: 14,                // групп в неделю (план)
  fill: 6,                   // факт детей в группе (моделируемое)
  material: 0,               // расходы на материалы
  visits: 2,                 // визитов в неделю (1× или 2×)
  _locked: false             // строка закреплена (нельзя редактировать)
}
```

### 3.3 Занятие (`S.grid[]`)

```javascript
{
  day: 1,                    // день недели: 1=Пн … 7=Вс
  start: "10:00",            // время начала
  end: "11:00",              // время конца
  room: "1 этаж №1",         // кабинет
  course: "Английский язык", // название курса (ссылка на courses.name)
  teacher: "Бурдук Наталья", // ФИО педагога (ссылка на staff.name)
  students: 6,               // число учеников
  note: "1. Иванов\n2. ...", // примечание (часто содержит список детей)
  newIntake: false,          // метка «новый набор» (подсветка оранжевым)
  pinned: false              // занятие закреплено
}
```

### 3.4 Педагог (`S.staff[]`)

```javascript
{
  name: "Бурдук Наталья Вячеславовна",
  canTeach: ["Английский язык"],  // какие курсы может вести
  wageMode: "tier",               // режим ЗП
  fixedRate: 0                    // фиксированная ставка
}
```

### 3.5 Справочник ставок (`S.wageTable`)

Ставка ЗП зависит от числа детей в группе (KPI-модель):

```javascript
{
  "Английский язык": {
    base: 40,
    tiers: {4:35, 5:36, 6:37, 7:38, 8:40, 9:42, 10:44, 11:47}
  }
}
```

### 3.6 Фикс-ставки (`S._fixRates`)

Альтернативная модель ЗП — ставка по педагогу и длительности:

```javascript
{
  "Зайцева Виктория": {60: 40, 90: 50},   // 60 мин = 40 руб, 90 мин = 50 руб
  "Козырев Влад": {90: 47}
}
```

### 3.7 Расходы

```javascript
S.fixed = {"Аренда": 9600, "Администраторы": 1840, ...}   // сумма/мес
S.fixedGroup = {"Аренда": 1, "Администраторы": 2}          // 1=общие, 2=админ-команда
S.fixedPct = [{name: "Бытовые расходы", pct: 5}]           // % от оборота
```

### 3.8 Налоги и допущения

```javascript
S.tax = {usn: 6, fszn: 34, belgosstrah: 0.6, acquiring: 1.0}
S.assume = {weeksPerMonth: 4, targetProfitShare: 18, ownerSalary: 0}
S.funnel = {coef: 1.32, now: 550, showRate: 0.8}
```

### 3.9 Версии модели («сценарии»)

Каждая версия — **полная копия всего `S`**. Хранятся списком:

```javascript
{
  "26/27": {...весь S...},
  "Основной": {...весь S...},
  "Не трогать": {...весь S...}
}
```

Текущие версии в системе: «26/27», «Основной», «Не трогать», «ТЕстовая», «26/27 от 6.07.2026» (+ несколько копий, часть битые).

---

## 4. КЛЮЧЕВАЯ БИЗНЕС-ЛОГИКА (что нельзя сломать)

### 4.1 Расчёт оборота
```
Оборот курса за месяц = цена × факт_детей × групп/нед × визитов/нед × недель_в_месяце
```
**Критично:** визиты (кратность в неделю) обязательны. Без них курсы с 2 занятиями в неделю считаются вдвое дешевле.

### 4.2 Расчёт ЗП педагога (две модели)

**Модель KPI** (по умолчанию): ставка зависит от числа детей в группе, берётся из `wageTable[курс].tiers[число_детей]`.

**Модель Фикс**: ставка зависит от педагога и длительности занятия, берётся из `_fixRates[педагог][длительность_в_минутах]`.

Переключатель `_wageMode` меняет расчёт **во всей модели сразу** (ФОТ, налоги, маржа, дашборд).

### 4.3 Налоги РБ
```
УСН = валовая выручка × 6%
База взносов = ФОТ педагогов + ФОТ админ-команды − ФОТ на НПД
ФСЗН = база × 34%
Белгосстрах = база × 0,6%
```
**Важно:** сотрудники на НПД (самозанятые — видеограф, СММ) взносами не облагаются, их ФОТ вычитается из базы.

### 4.4 Единый источник истины
Названия курсов и педагогов заводятся **ровно один раз** (в каталоге курсов / педсоставе), везде остальное — выпадающие списки. Это исключает расхождения.

Сопоставление курсов между разделами — **нестрогое** (без учёта регистра и лишних пробелов), иначе не находит.

---

## 5. ЦЕЛЕВАЯ АРХИТЕКТУРА (что строим)

### 5.1 Стек
- **Хостинг:** белорусский shared-хостинг с PHP 8.x и MySQL 8.x (hoster.by, active.by, domain.by — любой).
- **Бэкенд:** PHP (без фреймворка, простой REST API).
- **База:** MySQL.
- **Клиент:** тот же `index.html`, но обращается к своему API вместо Apps Script.
- **Домен:** поддомен клуба, например `model.proznanie.by`.

### 5.2 Почему так
- Shared-хостинг = не нужно администрировать сервер (обновления, безопасность, настройка — забота провайдера).
- PHP + MySQL работают на любом дешёвом тарифе «из коробки».
- Одна кнопка «Сохранить» → запрос к своему API → запись в MySQL. Быстро (доли секунды вместо 40 сек).
- Данные в нормальной базе, без лимитов на размер и без порчи JSON.

### 5.3 Схема базы данных

```sql
-- Версии модели (сценарии)
CREATE TABLE versions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL UNIQUE,
  is_current    TINYINT(1) DEFAULT 0,
  saved_at      DATETIME NOT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Курсы
CREATE TABLE courses (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  name          VARCHAR(200) NOT NULL,
  eco           VARCHAR(40),
  price         DECIMAL(10,2) DEFAULT 0,
  single_price  DECIMAL(10,2) DEFAULT 0,
  wage          DECIMAL(10,2) DEFAULT 0,
  duration      DECIMAL(4,2) DEFAULT 1,
  group_size    INT DEFAULT 8,
  groups_week   INT DEFAULT 0,
  fill_fact     INT DEFAULT 0,
  material      DECIMAL(10,2) DEFAULT 0,
  visits        INT DEFAULT 1,
  is_locked     TINYINT(1) DEFAULT 0,
  sort_order    INT DEFAULT 0,
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Расписание (занятия)
CREATE TABLE lessons (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  day_of_week   TINYINT NOT NULL,          -- 1=Пн … 7=Вс
  time_start    VARCHAR(5),                 -- "10:00"
  time_end      VARCHAR(5),                 -- "11:00"
  room          VARCHAR(80),
  course_name   VARCHAR(200),
  teacher_name  VARCHAR(200),
  students      INT DEFAULT 0,
  note          TEXT,                       -- примечание, часто со списком детей
  new_intake    TINYINT(1) DEFAULT 0,
  pinned        TINYINT(1) DEFAULT 0,
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version_day (version_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Педагоги
CREATE TABLE teachers (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  name          VARCHAR(200) NOT NULL,
  can_teach     TEXT,                       -- JSON-массив названий курсов
  wage_mode     VARCHAR(20) DEFAULT 'tier',
  fixed_rate    DECIMAL(10,2) DEFAULT 0,
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Фикс-ставки (педагог × длительность)
CREATE TABLE fix_rates (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  teacher_name  VARCHAR(200) NOT NULL,
  duration_min  INT NOT NULL,               -- 60, 90, 120
  rate          DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Справочник ставок ЗП по курсам (KPI-модель)
CREATE TABLE wage_tiers (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  course_name   VARCHAR(200) NOT NULL,
  base_rate     DECIMAL(10,2) DEFAULT 0,
  tiers_json    TEXT,                       -- {"4":35,"5":36,...}
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Постоянные расходы
CREATE TABLE expenses (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  name          VARCHAR(200) NOT NULL,
  amount        DECIMAL(12,2) DEFAULT 0,
  group_type    TINYINT DEFAULT 1,          -- 1=общие, 2=админ-команда
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Расходы в % от оборота
CREATE TABLE expenses_pct (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  name          VARCHAR(200) NOT NULL,
  pct           DECIMAL(5,2) DEFAULT 0,
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- План набора (планировщик загрузки)
CREATE TABLE plan_items (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  course_name   VARCHAR(200) NOT NULL,
  per_group     DECIMAL(6,2) DEFAULT 0,
  price         DECIMAL(10,2) DEFAULT 0,
  groups_week   INT DEFAULT 0,
  visits        INT DEFAULT 1,
  is_locked     TINYINT(1) DEFAULT 0,
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Настройки версии (налоги, допущения, режимы)
CREATE TABLE settings (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  tax_usn         DECIMAL(5,2) DEFAULT 6,
  tax_fszn        DECIMAL(5,2) DEFAULT 34,
  tax_belgos      DECIMAL(5,2) DEFAULT 0.6,
  tax_acquiring   DECIMAL(5,2) DEFAULT 1.0,
  npd_month       DECIMAL(12,2) DEFAULT 0,   -- ФОТ на НПД
  wage_mode       VARCHAR(10) DEFAULT 'kpi', -- kpi | fix
  weeks_per_month DECIMAL(4,2) DEFAULT 4,
  target_profit   DECIMAL(5,2) DEFAULT 18,
  owner_salary    DECIMAL(12,2) DEFAULT 0,
  funnel_coef     DECIMAL(6,3) DEFAULT 1.32,
  funnel_now      INT DEFAULT 0,
  funnel_showrate DECIMAL(4,2) DEFAULT 0.8,
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5.4 API (PHP)

Минимальный REST. Все ответы — JSON с заголовком `Content-Type: application/json`.

| Метод | Путь | Назначение |
|---|---|---|
| GET | `/api/load.php` | Отдать все версии + какая текущая |
| POST | `/api/save.php` | Сохранить все версии (полная перезапись) |
| POST | `/api/version.php?action=delete` | Удалить версию |
| POST | `/api/version.php?action=rename` | Переименовать версию |

**Формат ответа `load.php`** — тот же, что сейчас отдаёт Apps Script (чтобы клиент не переписывать целиком):

```json
{
  "ok": true,
  "data": {
    "scenarios": {
      "26/27": { "courses": [...], "grid": [...], ... },
      "Основной": { ... }
    },
    "current": "26/27",
    "savedAt": "2026-07-06T14:36:14.066Z"
  }
}
```

**Формат запроса `save.php`** — тот же, что сейчас шлёт клиент:

```json
{
  "action": "save",
  "data": {
    "scenarios": { "26/27": {...}, "Основной": {...} },
    "current": "26/27",
    "savedAt": "2026-07-06T14:36:14.066Z"
  }
}
```

**Ключевое требование:** сохранить совместимость форматов запроса/ответа с текущим Apps Script. Тогда в клиенте меняется **только URL** (`S.gasUrl` → `/api/`), и вся остальная логика работает без изменений.

### 5.5 Авторизация

Система для одного пользователя, но в интернете. Минимальная защита:
- Простой токен в заголовке `X-Auth-Token` (значение в конфиге PHP и в клиенте).
- Или HTTP Basic Auth средствами хостинга (`.htaccess`).
- **HTTPS обязателен** (у белорусских хостеров бесплатный Let's Encrypt).

Не усложнять: полноценная система пользователей не нужна.

---

## 6. ПЛАН МИГРАЦИИ (для Claude Code)

### Этап 1. Подготовка хостинга (делает Жанна)
1. Купить shared-хостинг с PHP 8 + MySQL у белорусского провайдера.
2. Привязать домен/поддомен (например `model.proznanie.by`), включить HTTPS.
3. Создать базу MySQL, получить: хост, имя БД, логин, пароль.
4. Получить доступ FTP или SSH.

**Что передать Claude Code:** доступы FTP/SSH, параметры MySQL, адрес домена.
⚠️ Пароли передавать безопасным способом, не в открытом чате.

### Этап 2. Бэкенд (делает Claude Code)
1. Создать структуру:
```
/public_html/
  index.html            ← клиент (текущий файл, с изменённым URL API)
  /api/
    config.php          ← параметры БД и токен (НЕ в git)
    db.php              ← подключение к MySQL (PDO)
    load.php            ← GET: отдать все версии
    save.php            ← POST: сохранить все версии
    version.php         ← POST: удалить/переименовать версию
  /schema/
    schema.sql          ← создание таблиц
```
2. Выполнить `schema.sql` (создать таблицы).
3. Реализовать API строго по форматам из раздела 5.4.
4. Настроить CORS, если клиент временно остаётся на GitHub Pages.

### Этап 3. Миграция данных
1. Выгрузить текущие данные из Google-таблицы: открыть в браузере
   `https://script.google.com/macros/s/AKfycbxJajbntVctGjXImc1hVhWimvOkXHcNCkuVFpszEbA8ad-B40Lj2Q8gdMMTBar4YLnsew/exec?action=load`
   → сохранить JSON.
2. Написать одноразовый скрипт-импортёр: JSON → таблицы MySQL.
3. **Проверить целостность:** число курсов, занятий, педагогов до и после должно совпадать.

⚠️ **Внимание:** часть версий в Google-таблице **повреждена** (обрывки вида `{"courses":[{"nar 1Z"}]}`). Импортировать только целые версии. Целые на момент написания: «Основной», «Не трогать», «26/27 от 6.07.2026 (копия)(копия)». Перед миграцией сверить с Жанной, какие версии актуальны.

### Этап 4. Клиент
1. В `index.html` заменить `S.gasUrl` на адрес нового API (`https://model.proznanie.by/api/`).
2. Проверить, что форматы запросов совпадают (см. 5.4) — если да, больше ничего менять не нужно.
3. Залить `index.html` на хостинг.

### Этап 5. Проверка
- [ ] Модель открывается за < 2 секунд.
- [ ] Все версии на месте, данные целые (курсы, расписание, педагоги).
- [ ] Сохранение работает, индикатор зелёный.
- [ ] Открыть с другого компьютера → данные те же.
- [ ] Переключение версий работает.
- [ ] Расчёты не изменились (сверить оборот и ФОТ до/после миграции).

### Этап 6. Отключение старого
Только после успешной проверки: отключить Apps Script, оставить Google-таблицу как архив.

---

## 7. ЧТО НЕ ЛОМАТЬ (грабли, на которые уже наступали)

1. **Формулу оборота**: цена × дети × группы × визиты × недели. Визиты обязательны.
2. **Две модели ЗП** (KPI и Фикс) — переключатель влияет на всю модель.
3. **Базу взносов**: ФОТ педагогов + админ-команда − НПД.
4. **Нестрогое сопоставление названий** курсов (без учёта регистра/пробелов).
5. **Метку времени версии** (`_savedAt`) ставить только при реальном сохранении, а не при каждом открытии — иначе ломается синхронизация между компьютерами.
6. **Не хранить весь JSON в одном поле** — это то, от чего уходим.

---

## 8. ЧТО МОЖНО УЛУЧШИТЬ ПОСЛЕ МИГРАЦИИ

Не обязательно, но станет возможным:
- История изменений (кто когда что менял) — таблица `audit_log`.
- Автосохранение без кнопки.
- Выгрузка в Google-таблицу как «витрина» по кнопке (не как хранилище).
- Разбиение `index.html` на модули (сейчас один файл на 230 КБ).
- Мобильная версия.

---

## 9. КОНТЕКСТ ДЛЯ РАБОТЫ

- Пользователь (Жанна) — директор клуба, не программист. Объяснять простым языком, без жаргона.
- Общение на русском.
- Все правки должны быть проверены перед отдачей (нельзя отдавать непроверенный код).
- Юрисдикция: Республика Беларусь. Налоги: УСН 6%, ФСЗН 34%, Белгосстрах 0,6%.
- Текущий репозиторий: `jane3dmp/finmodel-club` (GitHub).

---

## 10. ФАЙЛЫ ПРОЕКТА

| Файл | Что это |
|---|---|
| `index.html` | Весь клиент (интерфейс + логика), ~230 КБ |
| `apps_script_biblioteka.gs` | Текущий Google Apps Script (после миграции не нужен) |
| `HANDOFF.md` | Рабочие заметки по текущему состоянию |
| `DOCUMENTATION.md` | Этот документ |
