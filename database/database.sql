-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Wrz 15, 2026 at 11:44 AM
-- Wersja serwera: 10.4.32-MariaDB
-- Wersja PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `reserve-beauty`
--

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `dostepnosc_pracownikow`
--

CREATE TABLE `dostepnosc_pracownikow` (
  `id` int(11) NOT NULL,
  `id_pracownika` int(11) NOT NULL,
  `dzien_tygodnia` int(11) NOT NULL,
  `czas_rozpoczecia` time NOT NULL,
  `czas_zakonczenia` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dostepnosc_pracownikow`
--

INSERT INTO `dostepnosc_pracownikow` (`id`, `id_pracownika`, `dzien_tygodnia`, `czas_rozpoczecia`, `czas_zakonczenia`) VALUES
(1, 1, 5, '08:00:00', '16:00:00'),
(2, 1, 1, '10:00:00', '18:00:00'),
(3, 3, 3, '10:00:00', '18:00:00'),
(4, 2, 6, '08:00:00', '17:00:00'),
(5, 4, 2, '12:00:00', '20:00:00'),
(6, 5, 6, '10:00:00', '18:00:00'),
(7, 6, 4, '07:00:00', '15:00:00'),
(8, 5, 7, '11:00:00', '19:00:00');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `kategorie_uslug`
--

CREATE TABLE `kategorie_uslug` (
  `id` int(11) NOT NULL,
  `nazwa` varchar(150) NOT NULL,
  `opis` text DEFAULT NULL,
  `aktywny` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `kategorie_uslug`
--

INSERT INTO `kategorie_uslug` (`id`, `nazwa`, `opis`, `aktywny`) VALUES
(1, 'Paznokcie', NULL, 1),
(2, 'Brwi i rzęsy', NULL, 1),
(3, 'Pielęgnacja twarzy', NULL, 1),
(4, 'Makijaż', NULL, 1);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `pracownicy`
--

CREATE TABLE `pracownicy` (
  `id_pracownika` int(11) NOT NULL,
  `id_uzytkownika` int(11) NOT NULL,
  `id_salonu` int(11) NOT NULL,
  `opis` text DEFAULT NULL,
  `aktywnosc` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pracownicy`
--

INSERT INTO `pracownicy` (`id_pracownika`, `id_uzytkownika`, `id_salonu`, `opis`, `aktywnosc`) VALUES
(1, 5, 2, 'Brwi', 1),
(2, 9, 1, NULL, 1),
(3, 7, 3, 'Najlepszy pracownik od paznokci', 1),
(4, 10, 3, NULL, 1),
(5, 8, 1, 'Makijażystka', 1),
(6, 6, 3, NULL, 1);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `pracownicy_uslugi`
--

CREATE TABLE `pracownicy_uslugi` (
  `id_pracownika` int(11) NOT NULL,
  `id_uslugi` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pracownicy_uslugi`
--

INSERT INTO `pracownicy_uslugi` (`id_pracownika`, `id_uslugi`) VALUES
(1, 2),
(2, 4),
(3, 1),
(4, 1),
(4, 3),
(5, 4),
(6, 1),
(6, 3);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `rezerwacje`
--

CREATE TABLE `rezerwacje` (
  `nr_rezerwacji` int(11) NOT NULL,
  `id_uzytkownika` int(11) NOT NULL,
  `id_pracownika` int(11) NOT NULL,
  `id_uslugi` int(11) NOT NULL,
  `data_rozpoczecia` datetime NOT NULL,
  `data_zakonczenia` datetime NOT NULL,
  `cena` decimal(10,2) NOT NULL,
  `status` enum('oczekujaca','potwierdzona','zrealizowana','anulowana') NOT NULL DEFAULT 'oczekujaca',
  `uwagi` text DEFAULT NULL,
  `utworzono_dnia` datetime NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `rezerwacje`
--

INSERT INTO `rezerwacje` (`nr_rezerwacji`, `id_uzytkownika`, `id_pracownika`, `id_uslugi`, `data_rozpoczecia`, `data_zakonczenia`, `cena`, `status`, `uwagi`, `utworzono_dnia`) VALUES
(1, 3, 1, 2, '2026-09-07 12:00:00', '2026-09-07 12:30:00', 90.00, 'zrealizowana', NULL, '2026-09-01 15:19:07'),
(2, 2, 3, 1, '2026-09-30 14:00:00', '2026-09-30 15:00:00', 120.00, 'potwierdzona', NULL, '2026-09-13 13:09:46'),
(7, 4, 4, 1, '2027-01-05 17:00:00', '2027-01-05 18:00:00', 120.00, 'oczekujaca', NULL, '2026-09-13 13:28:09'),
(8, 2, 5, 4, '2026-09-12 13:00:00', '2026-09-12 14:40:00', 200.00, 'zrealizowana', NULL, '2026-07-05 16:10:38'),
(9, 3, 6, 3, '2026-09-24 09:00:00', '2026-09-24 09:45:00', 90.00, 'anulowana', NULL, '2026-09-13 13:28:09'),
(10, 4, 6, 3, '2026-09-24 07:00:00', '2026-09-24 07:45:00', 90.00, 'potwierdzona', NULL, '2026-09-10 09:24:38');

--
-- Wyzwalacze `rezerwacje`
--
DELIMITER $$
CREATE TRIGGER `sprawdz_nakladanie` BEFORE INSERT ON `rezerwacje` FOR EACH ROW BEGIN
    IF EXISTS (
        SELECT 1 FROM rezerwacje 
        WHERE id_pracownika = NEW.id_pracownika 
          AND status != 'anulowana'
          AND NEW.data_rozpoczecia < data_zakonczenia 
          AND NEW.data_zakonczenia > data_rozpoczecia
    ) THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Ten pracownik wykonuje już usługę w tym czasie!';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `sprawdz_nakladanie_update` BEFORE UPDATE ON `rezerwacje` FOR EACH ROW BEGIN
    IF EXISTS (
        SELECT 1 FROM rezerwacje 
        WHERE id_pracownika = NEW.id_pracownika 
          AND nr_rezerwacji != NEW.nr_rezerwacji
          AND status != 'anulowana'
          AND NEW.data_rozpoczecia < data_zakonczenia 
          AND NEW.data_zakonczenia > data_rozpoczecia
    ) THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Nie można zapisać zmian! Pracownik ma już rezerwację w tych godzinach.';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `salony`
--

CREATE TABLE `salony` (
  `id_salonu` int(11) NOT NULL,
  `nazwa` varchar(100) NOT NULL,
  `opis` text DEFAULT NULL,
  `miasto` varchar(100) NOT NULL,
  `ulica` varchar(200) NOT NULL,
  `nr_ulicy` varchar(8) NOT NULL,
  `aktywnosc` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `salony`
--

INSERT INTO `salony` (`id_salonu`, `nazwa`, `opis`, `miasto`, `ulica`, `nr_ulicy`, `aktywnosc`) VALUES
(1, 'Velvet Beauty', 'Studio makijażu', 'Warszawa', 'Przykładowa', '10', 1),
(2, 'Bloom Studio', NULL, 'Siedlce', 'Testowa', '5', 1),
(3, 'Luna Beauty', NULL, 'Łuków', 'Pokazowa', '8', 1);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `uslugi`
--

CREATE TABLE `uslugi` (
  `id_uslugi` int(11) NOT NULL,
  `id_salonu` int(11) NOT NULL,
  `id_kategoria` int(11) NOT NULL,
  `nazwa` varchar(150) NOT NULL,
  `opis` text DEFAULT NULL,
  `czas_trwania` int(11) NOT NULL,
  `cena` decimal(10,2) NOT NULL,
  `aktywnosc` tinyint(1) NOT NULL DEFAULT 1
) ;

--
-- Dumping data for table `uslugi`
--

INSERT INTO `uslugi` (`id_uslugi`, `id_salonu`, `id_kategoria`, `nazwa`, `opis`, `czas_trwania`, `cena`, `aktywnosc`) VALUES
(1, 3, 1, 'Manicure hybrydowy', NULL, 60, 120.00, 1),
(2, 2, 2, 'Stylizacja brwi', NULL, 30, 90.00, 1),
(3, 3, 3, 'Zabieg nawilżający', NULL, 45, 90.00, 1),
(4, 1, 4, 'Makijaż wieczorowy', NULL, 100, 200.00, 1);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `uzytkownicy`
--

CREATE TABLE `uzytkownicy` (
  `id_uzytkownika` int(11) NOT NULL,
  `imie` varchar(100) NOT NULL,
  `nazwisko` varchar(100) NOT NULL,
  `email` varchar(254) NOT NULL,
  `haslo` varchar(255) NOT NULL,
  `telefon` varchar(20) NOT NULL,
  `rola` enum('klient','pracownik','admin') NOT NULL DEFAULT 'klient',
  `aktywny` tinyint(1) NOT NULL DEFAULT 1,
  `utworzono_dnia` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `uzytkownicy`
--

INSERT INTO `uzytkownicy` (`id_uzytkownika`, `imie`, `nazwisko`, `email`, `haslo`, `telefon`, `rola`, `aktywny`, `utworzono_dnia`) VALUES
(1, 'Klaudia', 'Malak', 'admin123@gmail.com', '$2y$10$uE1eaISYiBM.GtljIu3z7eV2nD/6ZYWaIZlto1qMdlicsm4MCGBw2', '927942974', 'admin', 1, '2026-05-03 12:17:01'),
(2, 'Magdalena', 'Koch', 'madzia102@wp.pl', '$2y$10$uE1eaISYiBM.GtljIu3z7eV2nD/6ZYWaIZlto1qMdlicsm4MCGBw2', '939647173', 'klient', 1, '2026-06-16 12:17:01'),
(3, 'Michal', 'Ratal', 'ratal77@gmail.com', '$2y$10$uE1eaISYiBM.GtljIu3z7eV2nD/6ZYWaIZlto1qMdlicsm4MCGBw2', '937764928', 'klient', 1, '2026-04-30 07:17:01'),
(4, 'Dominika', 'Woryń', 'woryn_domi120@wp.pl', '$2y$10$uE1eaISYiBM.GtljIu3z7eV2nD/6ZYWaIZlto1qMdlicsm4MCGBw2', '873963258', 'klient', 1, '2026-06-01 14:24:01'),
(5, 'Katarzyna', 'Grabaczyk', 'Katarzyna_Grabaczyk@outlook.com', '$2y$10$uE1eaISYiBM.GtljIu3z7eV2nD/6ZYWaIZlto1qMdlicsm4MCGBw2', '767657567', 'pracownik', 1, '2025-04-15 12:17:01'),
(6, 'Barbara', 'Nataczna', 'BarbaraN999@gmail.com', '$2y$10$uE1eaISYiBM.GtljIu3z7eV2nD/6ZYWaIZlto1qMdlicsm4MCGBw2', '865348546', 'pracownik', 1, '2025-09-15 09:39:01'),
(7, 'Krystian', 'Kortans', 'KrystianKor@wp.pl', '$2y$10$uE1eaISYiBM.GtljIu3z7eV2nD/6ZYWaIZlto1qMdlicsm4MCGBw2', '745646547', 'pracownik', 1, '2024-10-13 17:45:01'),
(8, 'Weronika', 'Warecka', 'Warecka2000outlook.com', '$2y$10$uE1eaISYiBM.GtljIu3z7eV2nD/6ZYWaIZlto1qMdlicsm4MCGBw2', '545474897', 'pracownik', 1, '2026-01-17 21:08:12'),
(9, 'Amelia', 'Makiewicz', 'Makiewicz_Amelia@outlook.com', '$2y$10$uE1eaISYiBM.GtljIu3z7eV2nD/6ZYWaIZlto1qMdlicsm4MCGBw2', '428973568', 'pracownik', 1, '2023-11-01 09:49:37'),
(10, 'Alex', 'Kostewicz', 'Kostewicz67@gmail.com', '$2y$10$uE1eaISYiBM.GtljIu3z7eV2nD/6ZYWaIZlto1qMdlicsm4MCGBw2', '143579864', 'pracownik', 1, '2025-12-10 23:29:50');

--
-- Indeksy dla zrzutów tabel
--

--
-- Indeksy dla tabeli `dostepnosc_pracownikow`
--
ALTER TABLE `dostepnosc_pracownikow`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_pracownika` (`id_pracownika`);

--
-- Indeksy dla tabeli `kategorie_uslug`
--
ALTER TABLE `kategorie_uslug`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nazwa` (`nazwa`);

--
-- Indeksy dla tabeli `pracownicy`
--
ALTER TABLE `pracownicy`
  ADD PRIMARY KEY (`id_pracownika`),
  ADD UNIQUE KEY `id_uzytkownika` (`id_uzytkownika`),
  ADD KEY `id_salonu` (`id_salonu`);

--
-- Indeksy dla tabeli `pracownicy_uslugi`
--
ALTER TABLE `pracownicy_uslugi`
  ADD PRIMARY KEY (`id_pracownika`,`id_uslugi`),
  ADD KEY `id_uslugi` (`id_uslugi`);

--
-- Indeksy dla tabeli `rezerwacje`
--
ALTER TABLE `rezerwacje`
  ADD PRIMARY KEY (`nr_rezerwacji`),
  ADD KEY `id_uzytkownika` (`id_uzytkownika`),
  ADD KEY `id_pracownika` (`id_pracownika`),
  ADD KEY `id_uslugi` (`id_uslugi`);

--
-- Indeksy dla tabeli `salony`
--
ALTER TABLE `salony`
  ADD PRIMARY KEY (`id_salonu`);

--
-- Indeksy dla tabeli `uslugi`
--
ALTER TABLE `uslugi`
  ADD PRIMARY KEY (`id_uslugi`),
  ADD KEY `id_salonu` (`id_salonu`),
  ADD KEY `id_kategoria` (`id_kategoria`);

--
-- Indeksy dla tabeli `uzytkownicy`
--
ALTER TABLE `uzytkownicy`
  ADD PRIMARY KEY (`id_uzytkownika`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `dostepnosc_pracownikow`
--
ALTER TABLE `dostepnosc_pracownikow`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `kategorie_uslug`
--
ALTER TABLE `kategorie_uslug`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `pracownicy`
--
ALTER TABLE `pracownicy`
  MODIFY `id_pracownika` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `rezerwacje`
--
ALTER TABLE `rezerwacje`
  MODIFY `nr_rezerwacji` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `salony`
--
ALTER TABLE `salony`
  MODIFY `id_salonu` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `uslugi`
--
ALTER TABLE `uslugi`
  MODIFY `id_uslugi` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `uzytkownicy`
--
ALTER TABLE `uzytkownicy`
  MODIFY `id_uzytkownika` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `dostepnosc_pracownikow`
--
ALTER TABLE `dostepnosc_pracownikow`
  ADD CONSTRAINT `dostepnosc_pracownikow_ibfk_1` FOREIGN KEY (`id_pracownika`) REFERENCES `pracownicy` (`id_pracownika`) ON DELETE CASCADE;

--
-- Constraints for table `pracownicy`
--
ALTER TABLE `pracownicy`
  ADD CONSTRAINT `pracownicy_ibfk_1` FOREIGN KEY (`id_uzytkownika`) REFERENCES `uzytkownicy` (`id_uzytkownika`) ON DELETE CASCADE,
  ADD CONSTRAINT `pracownicy_ibfk_2` FOREIGN KEY (`id_salonu`) REFERENCES `salony` (`id_salonu`) ON DELETE CASCADE;

--
-- Constraints for table `pracownicy_uslugi`
--
ALTER TABLE `pracownicy_uslugi`
  ADD CONSTRAINT `pracownicy_uslugi_ibfk_1` FOREIGN KEY (`id_pracownika`) REFERENCES `pracownicy` (`id_pracownika`) ON DELETE CASCADE,
  ADD CONSTRAINT `pracownicy_uslugi_ibfk_2` FOREIGN KEY (`id_uslugi`) REFERENCES `uslugi` (`id_uslugi`) ON DELETE CASCADE;

--
-- Constraints for table `rezerwacje`
--
ALTER TABLE `rezerwacje`
  ADD CONSTRAINT `rezerwacje_ibfk_1` FOREIGN KEY (`id_uzytkownika`) REFERENCES `uzytkownicy` (`id_uzytkownika`) ON DELETE CASCADE,
  ADD CONSTRAINT `rezerwacje_ibfk_2` FOREIGN KEY (`id_pracownika`) REFERENCES `pracownicy` (`id_pracownika`) ON DELETE CASCADE,
  ADD CONSTRAINT `rezerwacje_ibfk_3` FOREIGN KEY (`id_uslugi`) REFERENCES `uslugi` (`id_uslugi`);

--
-- Constraints for table `uslugi`
--
ALTER TABLE `uslugi`
  ADD CONSTRAINT `uslugi_ibfk_1` FOREIGN KEY (`id_salonu`) REFERENCES `salony` (`id_salonu`) ON DELETE CASCADE,
  ADD CONSTRAINT `uslugi_ibfk_2` FOREIGN KEY (`id_kategoria`) REFERENCES `kategorie_uslug` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
