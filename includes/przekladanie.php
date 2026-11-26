<?php
require_once __DIR__ . '/rezerwacje.php';

function przelozId($wartosc): int
{
    return is_string($wartosc) ? (int) (filter_var($wartosc, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) ?: 0) : 0;
}

function przelozPobierz(PDO $pdo, int $klient, int $numer, bool $blokada = false): array
{
    $q = $pdo->prepare('SELECT r.*, us.id_salonu, us.nazwa AS usluga, s.nazwa AS salon,
        s.miasto, u.imie, u.nazwisko FROM rezerwacje r
        JOIN uslugi us ON us.id_uslugi = r.id_uslugi
        JOIN salony s ON s.id_salonu = us.id_salonu
        JOIN pracownicy p ON p.id_pracownika = r.id_pracownika
        JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika
        WHERE r.nr_rezerwacji = ? AND r.id_uzytkownika = ?' . ($blokada ? ' FOR UPDATE' : ''));
    $q->execute([$numer, $klient]);
    $w = $q->fetch(PDO::FETCH_ASSOC);
    if (!$w) { throw new RuntimeException('Nie znaleziono Twojej rezerwacji.'); }
    if (!in_array($w['status'], ['oczekujaca', 'potwierdzona'], true)
        || $w['data_rozpoczecia'] <= rezerwacjaTeraz()->format('Y-m-d H:i:s')) {
        throw new RuntimeException('Możesz przełożyć tylko przyszłą wizytę oczekującą lub potwierdzoną.');
    }
    $od = new DateTimeImmutable($w['data_rozpoczecia'], rezerwacjaStrefa());
    $do = new DateTimeImmutable($w['data_zakonczenia'], rezerwacjaStrefa());
    $sekundy = $do->getTimestamp() - $od->getTimestamp();
    if ($sekundy <= 0 || $sekundy % 60 !== 0 || $sekundy > 86400) {
        throw new RuntimeException('Wizyta ma nieprawidłową długość. Skontaktuj się z salonem.');
    }
    $w['czas_trwania'] = intdiv($sekundy, 60);
    return $w;
}

function przelozStan(array $w): array
{
    $stan = [];
    foreach (['nr_rezerwacji','id_uzytkownika','id_pracownika','id_uslugi','id_salonu',
        'data_rozpoczecia','data_zakonczenia','cena','status'] as $pole) { $stan[$pole] = (string) $w[$pole]; }
    return $stan;
}

function przelozGodziny(PDO $pdo, array $w, string $data, bool $blokada = false): array
{
    $dzien = rezerwacjaData($data);
    if (!$dzien) { throw new RuntimeException('Wybierz poprawną datę.'); }
    $lock = $blokada ? ' FOR UPDATE' : '';
    $q = $pdo->prepare('SELECT czas_rozpoczecia, czas_zakonczenia FROM dostepnosc_pracownikow
        WHERE id_pracownika = ? AND dzien_tygodnia = ? ORDER BY czas_rozpoczecia' . $lock);
    $q->execute([$w['id_pracownika'], (int) $dzien->format('N')]);
    $grafik = $q->fetchAll(PDO::FETCH_ASSOC);
    $q = $pdo->prepare("SELECT id_urlopu FROM urlopy WHERE id_pracownika = ? AND status = 'zaplanowany'
        AND data_od <= ? AND data_do >= ?" . $lock);
    $q->execute([$w['id_pracownika'], $data, $data]);
    $urlop = (bool) $q->fetchAll(PDO::FETCH_ASSOC);
    $q = $pdo->prepare("SELECT data_rozpoczecia, data_zakonczenia FROM rezerwacje
        WHERE id_pracownika = ? AND nr_rezerwacji <> ? AND status <> 'anulowana'
        AND data_rozpoczecia < ? AND data_zakonczenia > ?" . $lock);
    $q->execute([$w['id_pracownika'], $w['nr_rezerwacji'], $dzien->modify('+1 day')->format('Y-m-d H:i:s'), $data . ' 00:00:00']);
    $wizyty = $q->fetchAll(PDO::FETCH_ASSOC);
    return array_values(array_filter(rezerwacjaWyliczGodziny($data, $w['czas_trwania'], $grafik, $wizyty, $urlop),
        function ($t) use ($w) { return $t['data_rozpoczecia'] !== $w['data_rozpoczecia']; }));
}

function przelozZapisz(PDO $pdo, array $konto, array $oferta): void
{
    $pdo->beginTransaction();
    try {
        $stary = $oferta['stan'];
        // Ta sama kolejność co zapis wizyty i urlopu: salon, pracownik, rezerwacja.
        $q = $pdo->prepare('SELECT id_salonu FROM salony WHERE id_salonu = ? FOR UPDATE');
        $q->execute([$stary['id_salonu']]);
        if (!$q->fetch()) { throw new RuntimeException('Salon nie jest dostępny.'); }
        $q = $pdo->prepare('SELECT id_salonu FROM pracownicy WHERE id_pracownika = ? FOR UPDATE');
        $q->execute([$stary['id_pracownika']]);
        if ((int) $q->fetchColumn() !== (int) $stary['id_salonu']) { throw new RuntimeException('Pracownik zmienił salon. Odśwież stronę.'); }
        $w = przelozPobierz($pdo, (int) $konto['id_uzytkownika'], (int) $stary['nr_rezerwacji'], true);
        if (przelozStan($w) !== $stary) { throw new RuntimeException('Wizyta została zmieniona w międzyczasie. Wybierz nowy termin ponownie.'); }
        $q = $pdo->prepare("SELECT us.id_uslugi FROM uslugi us
            JOIN salony s ON s.id_salonu = us.id_salonu
            JOIN kategorie_uslug k ON k.id = us.id_kategoria
            JOIN pracownicy_uslugi pu ON pu.id_uslugi = us.id_uslugi
            JOIN pracownicy p ON p.id_pracownika = pu.id_pracownika AND p.id_salonu = us.id_salonu
            JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika
            WHERE us.id_uslugi = ? AND p.id_pracownika = ? AND us.id_salonu = ?
            AND us.aktywnosc = 1 AND s.aktywnosc = 1 AND k.aktywny = 1
            AND p.aktywnosc = 1 AND u.aktywny = 1 AND u.rola = 'pracownik' FOR UPDATE");
        $q->execute([$w['id_uslugi'], $w['id_pracownika'], $w['id_salonu']]);
        if (!$q->fetch()) { throw new RuntimeException('Usługa nie jest już dostępna u tego pracownika.'); }
        $q = $pdo->prepare("SELECT imie, nazwisko FROM uzytkownicy WHERE id_uzytkownika = ? AND aktywny = 1 AND rola = 'klient' FOR UPDATE");
        $q->execute([$konto['id_uzytkownika']]);
        $autor = $q->fetch(PDO::FETCH_ASSOC);
        if (!$autor) { throw new RuntimeException('Konto klienta nie jest aktywne.'); }
        $termin = null;
        foreach (przelozGodziny($pdo, $w, $oferta['data'], true) as $t) {
            if ($t['godzina'] === $oferta['godzina']) { $termin = $t; break; }
        }
        if (!$termin) { throw new RuntimeException('Ten termin jest już zajęty lub niedostępny. Dotychczasowa wizyta pozostaje bez zmian.'); }
        // Sprawdzamy czas ponownie po wszystkich oczekiwaniach na blokady.
        $teraz = rezerwacjaTeraz()->format('Y-m-d H:i:s');
        if ($w['data_rozpoczecia'] <= $teraz || $termin['data_rozpoczecia'] <= $teraz) {
            throw new RuntimeException('Wizyta już się rozpoczęła albo nowy termin minął. Nie zapisano zmiany.');
        }
        $q = $pdo->prepare("UPDATE rezerwacje SET data_rozpoczecia = ?, data_zakonczenia = ?, status = 'oczekujaca'
            WHERE nr_rezerwacji = ? AND id_uzytkownika = ?");
        $q->execute([$termin['data_rozpoczecia'], $termin['data_zakonczenia'], $w['nr_rezerwacji'], $konto['id_uzytkownika']]);
        if ($q->rowCount() !== 1) { throw new RuntimeException('Nie zapisano zmiany terminu.'); }
        $q = $pdo->prepare('INSERT INTO historia_terminow_rezerwacji
            (nr_rezerwacji, id_uzytkownika, poprzedni_poczatek, poprzedni_koniec, nowy_poczatek, nowy_koniec, zmieniono)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $q->execute([$w['nr_rezerwacji'], $konto['id_uzytkownika'], $w['data_rozpoczecia'], $w['data_zakonczenia'],
            $termin['data_rozpoczecia'], $termin['data_zakonczenia'], $teraz]);
        if ($w['status'] !== 'oczekujaca') {
            $q = $pdo->prepare('INSERT INTO historia_statusow_rezerwacji
                (nr_rezerwacji, id_uzytkownika_zmieniajacego, wykonawca, rola_wykonawcy, status_przed, status_po, zmieniono)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            $q->execute([$w['nr_rezerwacji'], $konto['id_uzytkownika'], $autor['imie'] . ' ' . $autor['nazwisko'],
                'klient', $w['status'], 'oczekujaca', $teraz]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}
