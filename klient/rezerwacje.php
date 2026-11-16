<?php
// Wspólne funkcje. Ten plik nie wyświetla strony i nie zapisuje rezerwacji.
// Połączenie $pdo przekazujemy z istniejącego includes/auth.php.
// W plikach korzystających z funkcji używaj require_once.
date_default_timezone_set('Europe/Warsaw');

function rezerwacjaStrefa(): DateTimeZone
{
    return new DateTimeZone('Europe/Warsaw');
}

function rezerwacjaTeraz(): DateTimeImmutable
{
    return new DateTimeImmutable('now', rezerwacjaStrefa());
}

// Zwraca datę albo null. Nie dopuszcza np. 31 lutego.
function rezerwacjaData(string $tekst): ?DateTimeImmutable
{
    if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $tekst)
        || $tekst < '1000-01-01' || $tekst > '9999-12-30') {
        return null;
    }
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $tekst, rezerwacjaStrefa());
    return $data && $data->format('Y-m-d') === $tekst ? $data : null;
}

function rezerwacjaGodzina(string $tekst): bool
{
    return preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $tekst) === 1;
}

// Nieistniejące i dwuznaczne godziny podczas zmiany czasu są odrzucane.
// Wasza kolumna DATETIME nie zapisuje, o które wystąpienie godziny chodzi.
function rezerwacjaTermin(string $data, string $godzina): ?DateTimeImmutable
{
    if (!rezerwacjaData($data) || !rezerwacjaGodzina($godzina)) {
        return null;
    }
    $tekst = $data . ' ' . $godzina;
    $termin = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $tekst, rezerwacjaStrefa());
    if (!$termin || $termin->format('Y-m-d H:i') !== $tekst) {
        return null;
    }
    foreach ([-3600, 3600] as $przesuniecie) {
        $inny = $termin->setTimestamp($termin->getTimestamp() + $przesuniecie);
        if ($inny->format('Y-m-d H:i') === $tekst) {
            return null;
        }
    }
    return $termin;
}

// Przedziały mogą się stykać: 09–10 oraz 10–11 nie kolidują.
// Argumenty muszą być poprawnymi datami w formacie Y-m-d H:i:s.
function rezerwacjaNakladaSie(string $od, string $do, string $inneOd, string $inneDo): bool
{
    return $od < $inneDo && $do > $inneOd;
}

function rezerwacjaGodzinyPracy(PDO $pdo, int $idPracownika, string $data): array
{
    $dzien = rezerwacjaData($data);
    if ($idPracownika < 1 || !$dzien) {
        throw new InvalidArgumentException('Wybierz pracownika i poprawną datę.');
    }
    $zapytanie = $pdo->prepare(
        'SELECT czas_rozpoczecia, czas_zakonczenia
         FROM dostepnosc_pracownikow
         WHERE id_pracownika = ? AND dzien_tygodnia = ?
         ORDER BY czas_rozpoczecia'
    );
    $zapytanie->execute([$idPracownika, (int) $dzien->format('N')]);
    return $zapytanie->fetchAll(PDO::FETCH_ASSOC);
}

// Obie daty urlopu są wliczone. Anulowany wpis nie blokuje terminów.
function rezerwacjaCzyUrlop(PDO $pdo, int $idPracownika, string $data): bool
{
    if ($idPracownika < 1 || !rezerwacjaData($data)) {
        throw new InvalidArgumentException('Wybierz pracownika i poprawną datę.');
    }
    $zapytanie = $pdo->prepare(
        "SELECT id_urlopu FROM urlopy
         WHERE id_pracownika = ? AND status = 'zaplanowany'
         AND data_od <= ? AND data_do >= ? LIMIT 1"
    );
    $zapytanie->execute([$idPracownika, $data, $data]);
    return $zapytanie->fetchColumn() !== false;
}

// Pobiera również wizyty zaczęte poprzedniego dnia i kończące się tego dnia.
// Zrealizowane zachowujemy jako zajęty czas, zgodnie z regułą: tylko anulowanie zwalnia.
function rezerwacjaWizytyDnia(PDO $pdo, int $idPracownika, string $data): array
{
    $dzien = rezerwacjaData($data);
    if ($idPracownika < 1 || !$dzien) {
        throw new InvalidArgumentException('Wybierz pracownika i poprawną datę.');
    }
    $zapytanie = $pdo->prepare(
        "SELECT nr_rezerwacji, data_rozpoczecia, data_zakonczenia
         FROM rezerwacje WHERE id_pracownika = ? AND status <> 'anulowana'
         AND data_rozpoczecia < ? AND data_zakonczenia > ?
         ORDER BY data_rozpoczecia"
    );
    $zapytanie->execute([
        $idPracownika,
        $dzien->modify('+1 day')->format('Y-m-d H:i:s'),
        $dzien->format('Y-m-d H:i:s')
    ]);
    return $zapytanie->fetchAll(PDO::FETCH_ASSOC);
}

function rezerwacjaCzyKolizja(array $wizyty, string $od, string $do): bool
{
    foreach ($wizyty as $wizyta) {
        if (rezerwacjaNakladaSie($od, $do, $wizyta['data_rozpoczecia'], $wizyta['data_zakonczenia'])) {
            return true;
        }
    }
    return false;
}

// Łączy stykające się godziny pracy; prawdziwa przerwa pozostaje przerwą.
function rezerwacjaPolaczGodziny(array $godziny): array
{
    usort($godziny, function ($a, $b) {
        return strcmp($a['czas_rozpoczecia'], $b['czas_rozpoczecia']);
    });
    $wynik = [];
    foreach ($godziny as $wiersz) {
        $od = $wiersz['czas_rozpoczecia'];
        $do = $wiersz['czas_zakonczenia'];
        $wzor = '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z/';
        if (!preg_match($wzor, $od) || !preg_match($wzor, $do) || $do <= $od) {
            throw new RuntimeException('Grafik zawiera niepoprawne godziny. Skontaktuj się z administratorem.');
        }
        $ostatni = count($wynik) - 1;
        if ($ostatni >= 0 && $od <= $wynik[$ostatni]['czas_zakonczenia']) {
            $wynik[$ostatni]['czas_zakonczenia'] = max($do, $wynik[$ostatni]['czas_zakonczenia']);
        } else {
            $wynik[] = $wiersz;
        }
    }
    return $wynik;
}

// Czyste obliczenia, bez zapytań SQL. Pomocnicza funkcja także do testowania.
// Kroki liczymy od północy: przy 15 minutach otrzymujemy 08:00, 08:15 itd.
function rezerwacjaWyliczGodziny(
    string $data,
    int $czasTrwania,
    array $godzinyPracy,
    array $wizyty,
    bool $czyUrlop,
    int $krok = 15,
    ?DateTimeImmutable $teraz = null
): array {
    $dzien = rezerwacjaData($data);
    if (!$dzien || $czasTrwania < 1 || $czasTrwania > 1440 || $krok < 1 || $krok > 1440) {
        throw new InvalidArgumentException('Niepoprawna data, czas trwania lub odstęp między terminami.');
    }
    $teraz = ($teraz ?? rezerwacjaTeraz())->setTimezone(rezerwacjaStrefa());
    if ($czyUrlop || $data < $teraz->format('Y-m-d')) {
        return [];
    }
    $godzinyPracy = rezerwacjaPolaczGodziny($godzinyPracy);
    $wynik = [];
    for ($minuta = 0; $minuta < 1440; $minuta += $krok) {
        $godzina = sprintf('%02d:%02d', intdiv($minuta, 60), $minuta % 60);
        $start = rezerwacjaTermin($data, $godzina);
        if (!$start || $start <= $teraz) {
            continue;
        }
        $koniec = $start->add(new DateInterval('PT' . $czasTrwania . 'M'));
        // Jeden przedział pracy mieści się w jednym dniu.
        // Nie proponujemy wizyt przechodzących przez zmianę czasu.
        if ($koniec->format('Y-m-d') !== $data || $start->getOffset() !== $koniec->getOffset()
            || !rezerwacjaTermin($data, $koniec->format('H:i'))) {
            continue;
        }
        $wPracy = false;
        foreach ($godzinyPracy as $przedzial) {
            if ($start->format('H:i:s') >= $przedzial['czas_rozpoczecia']
                && $koniec->format('H:i:s') <= $przedzial['czas_zakonczenia']) {
                $wPracy = true;
                break;
            }
        }
        $od = $start->format('Y-m-d H:i:s');
        $do = $koniec->format('Y-m-d H:i:s');
        if ($wPracy && !rezerwacjaCzyKolizja($wizyty, $od, $do)) {
            $wynik[] = ['godzina' => $godzina, 'data_rozpoczecia' => $od, 'data_zakonczenia' => $do];
        }
    }
    return $wynik;
}

// Sprawdza też przypisanie usługi, wspólny salon i wszystkie aktywności.
function rezerwacjaUslugaPracownika(PDO $pdo, int $idPracownika, int $idUslugi): array
{
    if ($idPracownika < 1 || $idUslugi < 1) {
        throw new InvalidArgumentException('Wybierz usługę i pracownika.');
    }
    $zapytanie = $pdo->prepare(
        "SELECT us.id_uslugi, us.id_salonu, us.nazwa, us.cena, us.czas_trwania
         FROM pracownicy_uslugi pu
         JOIN pracownicy p ON p.id_pracownika = pu.id_pracownika
         JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika
         JOIN uslugi us ON us.id_uslugi = pu.id_uslugi AND us.id_salonu = p.id_salonu
         JOIN salony s ON s.id_salonu = us.id_salonu
         JOIN kategorie_uslug k ON k.id = us.id_kategoria
         WHERE pu.id_pracownika = ? AND pu.id_uslugi = ?
         AND p.aktywnosc = 1 AND u.aktywny = 1 AND u.rola = 'pracownik'
         AND us.aktywnosc = 1 AND s.aktywnosc = 1 AND k.aktywny = 1"
    );
    $zapytanie->execute([$idPracownika, $idUslugi]);
    $usluga = $zapytanie->fetch(PDO::FETCH_ASSOC);
    if (!$usluga) {
        throw new RuntimeException('Ta usługa nie jest dostępna u wybranego pracownika.');
    }
    if ((int) $usluga['czas_trwania'] < 1 || (int) $usluga['czas_trwania'] > 1440
        || !is_numeric($usluga['cena']) || (float) $usluga['cena'] < 0) {
        throw new RuntimeException('Usługa ma niepoprawną cenę lub czas trwania. Skontaktuj się z administratorem.');
    }
    return $usluga;
}

// Główna funkcja do formularza: [] oznacza brak dostępnych godzin.
// Błędy bazy są przekazywane wyżej, a nie zamieniane na fałszywe wolne terminy.
function rezerwacjaDostepneGodziny(PDO $pdo, int $idPracownika, int $idUslugi, string $data, int $krok = 15): array
{
    $usluga = rezerwacjaUslugaPracownika($pdo, $idPracownika, $idUslugi);
    return rezerwacjaWyliczGodziny(
        $data,
        (int) $usluga['czas_trwania'],
        rezerwacjaGodzinyPracy($pdo, $idPracownika, $data),
        rezerwacjaWizytyDnia($pdo, $idPracownika, $data),
        rezerwacjaCzyUrlop($pdo, $idPracownika, $data),
        $krok
    );
}

// Reguły statusów dla administratora i pracownika.
// To NIE sprawdza uprawnień ani właściciela wizyty i NIE zapisuje zmian.
// Klientowi osobno pozwalamy wyłącznie anulować własną przyszłą wizytę.
function rezerwacjaDozwoloneStatusy(string $status, string $dataZakonczenia, ?DateTimeImmutable $teraz = null): array
{
    if ($status === 'oczekujaca') {
        return ['potwierdzona', 'anulowana'];
    }
    if ($status !== 'potwierdzona') {
        return [];
    }
    $wynik = ['anulowana'];
    $koniec = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dataZakonczenia, rezerwacjaStrefa());
    if ($koniec && $koniec->format('Y-m-d H:i:s') === $dataZakonczenia
        && $koniec <= ($teraz ?? rezerwacjaTeraz())) {
        $wynik[] = 'zrealizowana';
    }
    return $wynik;
}

function rezerwacjaCzyZmianaStatusu(string $status, string $nowyStatus, string $dataZakonczenia): bool
{
    return in_array($nowyStatus, rezerwacjaDozwoloneStatusy($status, $dataZakonczenia), true);
}

// UWAGA DOTYCZĄCA PRZYSZŁEGO ZAPISU:
// Wynik dostępności jest podglądem, a nie zablokowaniem terminu.
// Przed INSERT trzeba ponownie sprawdzić dane w transakcji z blokadą pracownika.
// Potrzebne są tabele InnoDB, wspólna kolejność blokad i aktualne odczyty SQL.
// Ten plik celowo nie rozpoczyna transakcji ani nie wykonuje INSERT/UPDATE.