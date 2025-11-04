-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Erstellungszeit: 04. Nov 2025 um 08:55
-- Server-Version: 9.0.1
-- PHP-Version: 8.3.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Datenbank: `zeitwerk`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `accounts`
--

CREATE TABLE `accounts` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(190) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `account_settings`
--

CREATE TABLE `account_settings` (
  `account_id` int NOT NULL,
  `invoice_number_pattern` varchar(255) NOT NULL DEFAULT '{YYYY}-{SEQ}',
  `invoice_seq_pad` tinyint UNSIGNED NOT NULL DEFAULT '5',
  `invoice_next_seq` int NOT NULL DEFAULT '1',
  `default_vat_rate` decimal(5,2) DEFAULT '19.00',
  `default_tax_scheme` enum('standard','tax_exempt','reverse_charge') DEFAULT 'standard',
  `default_due_days` int DEFAULT '14',
  `invoice_round_minutes` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `invoice_intro_text` text,
  `invoice_outro_text` text,
  `bank_iban` varchar(34) DEFAULT NULL,
  `bank_bic` varchar(11) DEFAULT NULL,
  `sender_address` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `companies`
--

CREATE TABLE `companies` (
  `id` bigint UNSIGNED NOT NULL,
  `account_id` bigint UNSIGNED NOT NULL,
  `name` varchar(190) NOT NULL,
  `address` text,
  `invoice_intro_text` text,
  `invoice_outro_text` text,
  `hourly_rate` decimal(10,2) DEFAULT NULL,
  `default_tax_scheme` enum('standard','tax_exempt','reverse_charge') DEFAULT NULL,
  `default_vat_rate` decimal(5,2) DEFAULT NULL,
  `vat_id` varchar(128) DEFAULT NULL,
  `status` enum('aktiv','abgeschlossen') NOT NULL DEFAULT 'aktiv',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `contacts`
--

CREATE TABLE `contacts` (
  `id` bigint UNSIGNED NOT NULL,
  `account_id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `salutation` enum('frau','herr','div') DEFAULT NULL,
  `first_name` varchar(150) NOT NULL DEFAULT '',
  `last_name` varchar(150) NOT NULL DEFAULT '',
  `department` varchar(120) DEFAULT NULL,
  `greeting_line` varchar(255) DEFAULT NULL,
  `is_invoice_addressee` tinyint(1) NOT NULL DEFAULT '0',
  `phone` varchar(64) DEFAULT NULL,
  `phone_alt` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `invoices`
--

CREATE TABLE `invoices` (
  `id` bigint UNSIGNED NOT NULL,
  `account_id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `invoice_number` varchar(190) DEFAULT NULL,
  `status` enum('in_vorbereitung','gestellt','gemahnt','bezahlt','storniert','ausgebucht') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'in_vorbereitung',
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `tax_exemption_reason` text,
  `invoice_intro_text` text,
  `invoice_outro_text` text,
  `total_net` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_gross` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` bigint UNSIGNED NOT NULL,
  `account_id` bigint UNSIGNED NOT NULL,
  `invoice_id` bigint UNSIGNED NOT NULL,
  `project_id` bigint UNSIGNED DEFAULT NULL,
  `task_id` bigint UNSIGNED DEFAULT NULL,
  `description` text NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT '0.000',
  `unit_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `vat_rate` decimal(5,2) NOT NULL DEFAULT '19.00',
  `total_net` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_gross` decimal(12,2) NOT NULL DEFAULT '0.00',
  `position` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `tax_scheme` enum('standard','tax_exempt','reverse_charge') DEFAULT NULL,
  `entry_mode` enum('auto','time','qty') NOT NULL DEFAULT 'auto'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `invoice_item_times`
--

CREATE TABLE `invoice_item_times` (
  `account_id` bigint UNSIGNED NOT NULL,
  `invoice_item_id` bigint UNSIGNED NOT NULL,
  `time_id` bigint UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `projects`
--

CREATE TABLE `projects` (
  `id` bigint UNSIGNED NOT NULL,
  `account_id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED NOT NULL,
  `contact_id` bigint UNSIGNED DEFAULT NULL,
  `title` varchar(190) NOT NULL,
  `status` enum('offen','abgeschlossen','angeboten') NOT NULL DEFAULT 'offen',
  `hourly_rate` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `recurring_items`
--

CREATE TABLE `recurring_items` (
  `id` int NOT NULL,
  `account_id` int NOT NULL,
  `company_id` int NOT NULL,
  `description_tpl` varchar(255) NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT '1.000',
  `unit_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tax_scheme` enum('standard','tax_exempt','reverse_charge') NOT NULL DEFAULT 'standard',
  `vat_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `interval_unit` enum('day','week','month','quarter','year') NOT NULL DEFAULT 'month',
  `interval_count` int NOT NULL DEFAULT '1',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `last_invoiced_until` date DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `recurring_item_ledger`
--

CREATE TABLE `recurring_item_ledger` (
  `id` int NOT NULL,
  `account_id` int NOT NULL,
  `company_id` int NOT NULL,
  `recurring_item_id` int NOT NULL,
  `period_from` date NOT NULL,
  `period_to` date NOT NULL,
  `invoice_id` bigint UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `tasks`
--

CREATE TABLE `tasks` (
  `id` bigint UNSIGNED NOT NULL,
  `account_id` bigint UNSIGNED NOT NULL,
  `project_id` bigint UNSIGNED NOT NULL,
  `description` text NOT NULL,
  `planned_minutes` int DEFAULT NULL,
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `deadline` date DEFAULT NULL,
  `status` enum('angeboten','offen','warten','abgeschlossen') NOT NULL DEFAULT 'offen',
  `billable` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `task_ordering_global`
--

CREATE TABLE `task_ordering_global` (
  `account_id` int NOT NULL,
  `task_id` int NOT NULL,
  `position` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `times`
--

CREATE TABLE `times` (
  `id` bigint UNSIGNED NOT NULL,
  `account_id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `task_id` bigint UNSIGNED DEFAULT NULL,
  `started_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  `minutes` int DEFAULT NULL,
  `billable` tinyint(1) NOT NULL DEFAULT '1',
  `status` enum('offen','in_abrechnung','abgerechnet') NOT NULL DEFAULT 'offen',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Trigger `times`
--
DELIMITER $$
CREATE TRIGGER `times_bi_enforce_billable` BEFORE INSERT ON `times` FOR EACH ROW BEGIN
  DECLARE t_billable INT DEFAULT 1;
  IF NEW.task_id IS NOT NULL THEN
    SELECT billable INTO t_billable
    FROM tasks
    WHERE id = NEW.task_id AND account_id = NEW.account_id
    LIMIT 1;
    IF t_billable = 0 THEN
      SET NEW.billable = 0;
    END IF;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `times_bu_enforce_billable` BEFORE UPDATE ON `times` FOR EACH ROW BEGIN
  DECLARE t_billable INT DEFAULT 1;
  IF NEW.task_id IS NOT NULL THEN
    SELECT billable INTO t_billable
    FROM tasks
    WHERE id = NEW.task_id AND account_id = NEW.account_id
    LIMIT 1;
    IF t_billable = 0 THEN
      SET NEW.billable = 0;
    END IF;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_times_task_account_check_ins` BEFORE INSERT ON `times` FOR EACH ROW BEGIN
  DECLARE t_acc INT;  -- Variablen-Declaration MUSS am Block-Anfang stehen

  IF NEW.task_id IS NOT NULL THEN
    SELECT account_id INTO t_acc
    FROM tasks
    WHERE id = NEW.task_id
    LIMIT 1;

    IF t_acc IS NULL OR t_acc <> NEW.account_id THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'times.account_id must match tasks.account_id';
    END IF;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_times_task_account_check_upd` BEFORE UPDATE ON `times` FOR EACH ROW BEGIN
  DECLARE t_acc INT;

  IF NEW.task_id IS NOT NULL THEN
    SELECT account_id INTO t_acc
    FROM tasks
    WHERE id = NEW.task_id
    LIMIT 1;

    IF t_acc IS NULL OR t_acc <> NEW.account_id THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'times.account_id must match tasks.account_id';
    END IF;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `users`
--

CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL,
  `account_id` bigint UNSIGNED NOT NULL,
  `name` varchar(190) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `account_settings`
--
ALTER TABLE `account_settings`
  ADD PRIMARY KEY (`account_id`);

--
-- Indizes für die Tabelle `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_companies_account_id_id` (`account_id`,`id`),
  ADD KEY `account_id` (`account_id`,`status`);

--
-- Indizes für die Tabelle `contacts`
--
ALTER TABLE `contacts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_contacts_account_id_id` (`account_id`,`id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `account_id` (`account_id`,`company_id`),
  ADD KEY `idx_contacts_acc_company_name` (`account_id`,`company_id`),
  ADD KEY `idx_contacts_invoice_addressee` (`is_invoice_addressee`);

--
-- Indizes für die Tabelle `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_invoices_account_id_id` (`account_id`,`id`),
  ADD UNIQUE KEY `uniq_account_invoice_no` (`account_id`,`invoice_number`),
  ADD KEY `account_id` (`account_id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `idx_invoices_status` (`status`),
  ADD KEY `idx_invoices_issue_date` (`issue_date`),
  ADD KEY `idx_invoices_due_date` (`due_date`),
  ADD KEY `idx_invoices_acc_number` (`account_id`,`invoice_number`),
  ADD KEY `fk_invoices_company_acc` (`account_id`,`company_id`);

--
-- Indizes für die Tabelle `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_invoice_items_account_id_id` (`account_id`,`id`),
  ADD KEY `account_id` (`account_id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `idx_items_invoice_acc` (`account_id`,`invoice_id`),
  ADD KEY `idx_items_project_acc` (`account_id`,`project_id`),
  ADD KEY `idx_items_task_acc` (`account_id`,`task_id`);

--
-- Indizes für die Tabelle `invoice_item_times`
--
ALTER TABLE `invoice_item_times`
  ADD PRIMARY KEY (`account_id`,`invoice_item_id`,`time_id`),
  ADD KEY `idx_iit_item` (`invoice_item_id`),
  ADD KEY `idx_iit_time` (`time_id`),
  ADD KEY `fk_iit_time_acc` (`account_id`,`time_id`);

--
-- Indizes für die Tabelle `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_projects_account_id_id` (`account_id`,`id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `contact_id` (`contact_id`),
  ADD KEY `account_id` (`account_id`,`company_id`,`status`),
  ADD KEY `idx_projects_acc_comp_stat_title` (`account_id`,`company_id`,`status`,`title`);

--
-- Indizes für die Tabelle `recurring_items`
--
ALTER TABLE `recurring_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `acc_company` (`account_id`,`company_id`);

--
-- Indizes für die Tabelle `recurring_item_ledger`
--
ALTER TABLE `recurring_item_ledger`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_run` (`account_id`,`recurring_item_id`,`period_from`,`period_to`),
  ADD KEY `k_invoice` (`account_id`,`invoice_id`);

--
-- Indizes für die Tabelle `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tasks_account_id_id` (`account_id`,`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `account_id` (`account_id`,`project_id`,`status`),
  ADD KEY `idx_tasks_acc_proj_stat_prio_dead` (`account_id`,`project_id`,`status`,`priority`,`deadline`);

--
-- Indizes für die Tabelle `task_ordering_global`
--
ALTER TABLE `task_ordering_global`
  ADD PRIMARY KEY (`account_id`,`task_id`),
  ADD KEY `idx_task_ordering_acc_pos` (`account_id`,`position`);

--
-- Indizes für die Tabelle `times`
--
ALTER TABLE `times`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_times_account_id_id` (`account_id`,`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `account_id` (`account_id`,`user_id`,`status`),
  ADD KEY `account_id_2` (`account_id`,`task_id`),
  ADD KEY `idx_times_acc_user_task_start_end` (`account_id`,`user_id`,`task_id`,`started_at`,`ended_at`),
  ADD KEY `idx_times_task_id` (`task_id`);

--
-- Indizes für die Tabelle `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `account_id` (`account_id`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `companies`
--
ALTER TABLE `companies`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `contacts`
--
ALTER TABLE `contacts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `projects`
--
ALTER TABLE `projects`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `recurring_items`
--
ALTER TABLE `recurring_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `recurring_item_ledger`
--
ALTER TABLE `recurring_item_ledger`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `times`
--
ALTER TABLE `times`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints der exportierten Tabellen
--

--
-- Constraints der Tabelle `companies`
--
ALTER TABLE `companies`
  ADD CONSTRAINT `companies_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `contacts`
--
ALTER TABLE `contacts`
  ADD CONSTRAINT `contacts_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `contacts_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_contacts_company_acc` FOREIGN KEY (`account_id`,`company_id`) REFERENCES `companies` (`account_id`, `id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints der Tabelle `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_invoices_company_acc` FOREIGN KEY (`account_id`,`company_id`) REFERENCES `companies` (`account_id`, `id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `fk_invoice_items_invoice_acc` FOREIGN KEY (`account_id`,`invoice_id`) REFERENCES `invoices` (`account_id`, `id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invoice_items_project_acc` FOREIGN KEY (`account_id`,`project_id`) REFERENCES `projects` (`account_id`, `id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invoice_items_task_acc` FOREIGN KEY (`account_id`,`task_id`) REFERENCES `tasks` (`account_id`, `id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `invoice_items_ibfk_3` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `invoice_items_ibfk_4` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints der Tabelle `invoice_item_times`
--
ALTER TABLE `invoice_item_times`
  ADD CONSTRAINT `fk_iit_item_acc` FOREIGN KEY (`account_id`,`invoice_item_id`) REFERENCES `invoice_items` (`account_id`, `id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_iit_time_acc` FOREIGN KEY (`account_id`,`time_id`) REFERENCES `times` (`account_id`, `id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints der Tabelle `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `fk_projects_company_acc` FOREIGN KEY (`account_id`,`company_id`) REFERENCES `companies` (`account_id`, `id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `projects_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `projects_ibfk_3` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL;

--
-- Constraints der Tabelle `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `fk_tasks_project_acc` FOREIGN KEY (`account_id`,`project_id`) REFERENCES `projects` (`account_id`, `id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tasks_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `times`
--
ALTER TABLE `times`
  ADD CONSTRAINT `fk_times_task_id` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `times_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `times_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
