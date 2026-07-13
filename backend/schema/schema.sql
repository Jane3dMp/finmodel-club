-- Финмодель «Прознание + CODDY» — схема базы данных
-- MySQL 8.x / MariaDB 10.x
-- Выполнить один раз при развёртывании.

SET NAMES utf8mb4;

-- Версии модели (сценарии «что если»)
CREATE TABLE IF NOT EXISTS versions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL UNIQUE,
  is_current    TINYINT(1) DEFAULT 0,
  saved_at      DATETIME NOT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Курсы (каталог: цена, ставка ЗП, наполняемость)
CREATE TABLE IF NOT EXISTS courses (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  name          VARCHAR(200) NOT NULL,
  eco           VARCHAR(40),                 -- Прознание | CODDY | Детали
  price         DECIMAL(10,2) DEFAULT 0,     -- цена за занятие
  single_price  DECIMAL(10,2) DEFAULT 0,     -- цена разового
  wage          DECIMAL(10,2) DEFAULT 0,     -- базовая ставка ЗП
  duration      DECIMAL(4,2) DEFAULT 1,      -- длительность, часов
  group_size    INT DEFAULT 8,               -- целевой размер группы
  groups_week   INT DEFAULT 0,               -- групп в неделю (план)
  fill_fact     INT DEFAULT 0,               -- факт детей в группе
  material      DECIMAL(10,2) DEFAULT 0,     -- расходы на материалы
  visits        INT DEFAULT 1,               -- визитов в неделю (1 или 2)
  is_locked     TINYINT(1) DEFAULT 0,
  sort_order    INT DEFAULT 0,
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Расписание (занятия в сетке)
CREATE TABLE IF NOT EXISTS lessons (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  day_of_week   TINYINT NOT NULL,            -- 1=Пн … 7=Вс
  time_start    VARCHAR(5),                  -- "10:00"
  time_end      VARCHAR(5),                  -- "11:00"
  room          VARCHAR(80),
  course_name   VARCHAR(200),
  teacher_name  VARCHAR(200),
  students      INT DEFAULT 0,
  note          TEXT,                        -- примечание (часто список детей)
  new_intake    TINYINT(1) DEFAULT 0,        -- метка «новый набор»
  pinned        TINYINT(1) DEFAULT 0,
  sort_order    INT DEFAULT 0,
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version_day (version_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Педагоги
CREATE TABLE IF NOT EXISTS teachers (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  name          VARCHAR(200) NOT NULL,
  can_teach     TEXT,                        -- JSON-массив курсов
  wage_mode     VARCHAR(20) DEFAULT 'tier',
  fixed_rate    DECIMAL(10,2) DEFAULT 0,
  sort_order    INT DEFAULT 0,
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Фикс-ставки ЗП (педагог × длительность занятия)
CREATE TABLE IF NOT EXISTS fix_rates (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  teacher_name  VARCHAR(200) NOT NULL,
  duration_min  INT NOT NULL,                -- 60, 90, 120
  rate          DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Справочник ставок ЗП по курсам (KPI-модель: ставка от числа детей)
CREATE TABLE IF NOT EXISTS wage_tiers (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  course_name   VARCHAR(200) NOT NULL,
  base_rate     DECIMAL(10,2) DEFAULT 0,
  tiers_json    TEXT,                        -- {"4":35,"5":36,"6":37,...}
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Постоянные расходы
CREATE TABLE IF NOT EXISTS expenses (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  name          VARCHAR(200) NOT NULL,
  amount        DECIMAL(12,2) DEFAULT 0,
  group_type    TINYINT DEFAULT 1,           -- 1=общие, 2=админ-команда
  sort_order    INT DEFAULT 0,
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Расходы в % от оборота
CREATE TABLE IF NOT EXISTS expenses_pct (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  name          VARCHAR(200) NOT NULL,
  pct           DECIMAL(5,2) DEFAULT 0,
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- План набора (планировщик загрузки)
CREATE TABLE IF NOT EXISTS plan_items (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  version_id    INT NOT NULL,
  course_name   VARCHAR(200) NOT NULL,
  per_group     DECIMAL(6,2) DEFAULT 0,      -- детей в группе (план)
  price         DECIMAL(10,2) DEFAULT 0,
  groups_week   INT DEFAULT 0,
  visits        INT DEFAULT 1,
  is_locked     TINYINT(1) DEFAULT 0,
  sort_order    INT DEFAULT 0,
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  INDEX idx_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Настройки версии: налоги, допущения, режимы
CREATE TABLE IF NOT EXISTS settings (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  version_id      INT NOT NULL,
  tax_usn         DECIMAL(5,2) DEFAULT 6,    -- УСН, %
  tax_fszn        DECIMAL(5,2) DEFAULT 34,   -- ФСЗН, %
  tax_belgos      DECIMAL(5,2) DEFAULT 0.6,  -- Белгосстрах, %
  tax_acquiring   DECIMAL(5,2) DEFAULT 1.0,  -- эквайринг, %
  npd_month       DECIMAL(12,2) DEFAULT 0,   -- ФОТ на НПД (не облагается)
  wage_mode       VARCHAR(10) DEFAULT 'kpi', -- kpi | fix
  weeks_per_month DECIMAL(4,2) DEFAULT 4,
  target_profit   DECIMAL(5,2) DEFAULT 18,
  owner_salary    DECIMAL(12,2) DEFAULT 0,
  funnel_coef     DECIMAL(6,3) DEFAULT 1.32,
  funnel_now      INT DEFAULT 0,
  funnel_showrate DECIMAL(4,2) DEFAULT 0.8,
  extra_json      TEXT,                      -- прочие поля состояния (запас на будущее)
  FOREIGN KEY (version_id) REFERENCES versions(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
