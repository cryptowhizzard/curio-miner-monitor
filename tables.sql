-- phpMyAdmin SQL Dump
-- version 5.1.1deb5ubuntu1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Gegenereerd op: 07 okt 2025 om 13:24
-- Serverversie: 8.0.43-0ubuntu0.22.04.1
-- PHP-versie: 8.1.2-1ubuntu2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Database: `miner_data`
--

-- --------------------------------------------------------

--
-- Tabelstructuur voor tabel `blocks`
--

CREATE TABLE `blocks` (
  `id` int NOT NULL,
  `miner_id` varchar(50) NOT NULL,
  `timestamp` int DEFAULT NULL,
  `block_data` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabelstructuur voor tabel `daily_luck`
--

CREATE TABLE `daily_luck` (
  `id` int NOT NULL,
  `miner_id` varchar(50) NOT NULL,
  `date` date DEFAULT NULL,
  `luck_rate` float DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabelstructuur voor tabel `miners`
--

CREATE TABLE `miners` (
  `id` int NOT NULL,
  `miner_id` varchar(50) NOT NULL,
  `miner_name` varchar(100) NOT NULL,
  `adjusted_power` float DEFAULT NULL,
  `available_balance` float DEFAULT NULL,
  `worker_balance` float DEFAULT NULL,
  `faulty_sectors` int DEFAULT NULL,
  `last24_hours_blocks` int DEFAULT NULL,
  `luck_rate_24_hours` float DEFAULT NULL,
  `last7_days_blocks` int DEFAULT NULL,
  `luck_rate_7_days` float DEFAULT NULL,
  `last30_days_blocks` int DEFAULT NULL,
  `luck_rate_30_days` float DEFAULT NULL,
  `date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_checked` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Tabelstructuur voor tabel `power`
--

CREATE TABLE `power` (
  `id` int NOT NULL,
  `miner_id` varchar(50) NOT NULL,
  `power` float DEFAULT NULL,
  `network_quality_power` float DEFAULT NULL,
  `date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexen voor geëxporteerde tabellen
--

--
-- Indexen voor tabel `blocks`
--
ALTER TABLE `blocks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_block` (`miner_id`,`timestamp`);

--
-- Indexen voor tabel `daily_luck`
--
ALTER TABLE `daily_luck`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_luck` (`miner_id`,`date`);

--
-- Indexen voor tabel `miners`
--
ALTER TABLE `miners`
  ADD PRIMARY KEY (`id`);

--
-- Indexen voor tabel `power`
--
ALTER TABLE `power`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT voor geëxporteerde tabellen
--

--
-- AUTO_INCREMENT voor een tabel `blocks`
--
ALTER TABLE `blocks`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT voor een tabel `daily_luck`
--
ALTER TABLE `daily_luck`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT voor een tabel `miners`
--
ALTER TABLE `miners`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT voor een tabel `power`
--
ALTER TABLE `power`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
COMMIT;

