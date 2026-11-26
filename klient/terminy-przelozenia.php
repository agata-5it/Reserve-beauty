<?php
// Endpoint tylko odczytuje terminy. Nie zapisuje wizyty ani podsumowania.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function terminyJson(int $kod, array $dane): void
{
    http_response_code($kod);
    echo json_encode($dane, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    terminyJson(405, ['ok' => false, 'komunikat' => 'Nieprawidłowa metoda zapytania.']);
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/przekladanie.php';

try {
    $konto = zalogowanyUzytkownik();
    if (!$konto) {
        terminyJson(401, ['ok' => false, 'komunikat' => 'Sesja wygasła. Zaloguj się ponownie i odśwież formularz.']);
    }
    if ($konto['rola'] !== 'klient') {
        terminyJson(403, ['ok' => false, 'komunikat' => 'Wybór terminu jest dostępny dla zalogowanego klienta.']);
    }
    // Zwolnienie sesji pozwala przetwarzać kolejne zapytania tej samej osoby.
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

    $numer = przelozId($_GET['nr_rezerwacji'] ?? null);
    $w = przelozPobierz($pdo, (int) $konto['id_uzytkownika'], $numer);
    rezerwacjaUslugaPracownika($pdo, (int) $w['id_pracownika'], (int) $w['id_uslugi']);
    $id = ['id_uslugi' => (int) $w['id_uslugi'], 'id_pracownika' => (int) $w['id_pracownika']];
    // Widok miesiąca: cztery odczyty zamiast osobnego zestawu zapytań na każdy dzień.
    if (isset($_GET['miesiac'])) {
        $miesiac = $_GET['miesiac'];
        $poczatek = is_string($miesiac) && preg_match('/\A[0-9]{4}-[0-9]{2}\z/', $miesiac)
            ? rezerwacjaData($miesiac . '-01') : null;
        if (!$poczatek) {
            terminyJson(400, ['ok' => false, 'komunikat' => 'Wybierz poprawny miesiąc.']);
        }
        $ostatni = $poczatek->format('Y-m-t');
        $granica = $miesiac === '9999-12' ? '9999-12-31 00:00:00'
            : $poczatek->modify('+1 month')->format('Y-m-d H:i:s');
        $usluga = $w;
        $q = $pdo->prepare('SELECT dzien_tygodnia, czas_rozpoczecia, czas_zakonczenia
            FROM dostepnosc_pracownikow WHERE id_pracownika = ? ORDER BY czas_rozpoczecia');
        $q->execute([$id['id_pracownika']]);
        $grafik = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $wiersz) {
            $grafik[(int) $wiersz['dzien_tygodnia']][] = $wiersz;
        }
        $q = $pdo->prepare("SELECT data_od, data_do FROM urlopy WHERE id_pracownika = ?
            AND status = 'zaplanowany' AND data_od <= ? AND data_do >= ?");
        $q->execute([$id['id_pracownika'], $ostatni, $poczatek->format('Y-m-d')]);
        $urlopy = $q->fetchAll(PDO::FETCH_ASSOC);
        $q = $pdo->prepare("SELECT data_rozpoczecia, data_zakonczenia FROM rezerwacje
            WHERE id_pracownika = ? AND nr_rezerwacji <> ? AND status <> 'anulowana'
            AND data_rozpoczecia < ? AND data_zakonczenia > ?");
        $q->execute([$id['id_pracownika'], $numer, $granica, $poczatek->format('Y-m-d H:i:s')]);
        $wizyty = $q->fetchAll(PDO::FETCH_ASSOC);
        $teraz = rezerwacjaTeraz();
        $dni = [];
        for ($nr = 1; $nr <= (int) $poczatek->format('t'); $nr++) {
            $dzien = $poczatek->modify('+' . ($nr - 1) . ' days');
            $dataDnia = $dzien->format('Y-m-d');
            $urlop = false;
            foreach ($urlopy as $wpis) {
                if ($wpis['data_od'] <= $dataDnia && $wpis['data_do'] >= $dataDnia) {
                    $urlop = true;
                    break;
                }
            }
            $godziny = rezerwacjaData($dataDnia) ? rezerwacjaWyliczGodziny(
                $dataDnia, (int) $usluga['czas_trwania'], $grafik[(int) $dzien->format('N')] ?? [],
                $wizyty, $urlop, 15, $teraz
            ) : [];
            // Udostępniamy tylko liczbę wolnych godzin, bez danych cudzych wizyt.
            $godziny = array_filter($godziny, function ($t) use ($w) { return $t['data_rozpoczecia'] !== $w['data_rozpoczecia']; });
            $dni[] = ['data' => $dataDnia, 'liczba' => count($godziny)];
        }
        terminyJson(200, ['ok' => true, 'id_uslugi' => (int) $id['id_uslugi'],
            'id_pracownika' => (int) $id['id_pracownika'], 'miesiac' => $miesiac,
            'dzisiaj' => $teraz->format('Y-m-d'), 'dni' => $dni]);
    }

    $data = $_GET['data'] ?? null;
    if (!is_string($data) || !rezerwacjaData($data) || $data < rezerwacjaTeraz()->format('Y-m-d')) {
        terminyJson(400, ['ok' => false, 'komunikat' => 'Wybierz poprawną datę, nie wcześniejszą niż dzisiaj.']);
    }
    // Ta sama funkcja co w projekcie: aktywności, wspólny salon i przypisanie,
    // grafik (brak grafiku = brak godzin), zaplanowany urlop, kolizje i przeszłość.
    $godziny = przelozGodziny($pdo, $w, $data);
    terminyJson(200, ['ok' => true, 'id_uslugi' => (int) $id['id_uslugi'],
        'id_pracownika' => (int) $id['id_pracownika'], 'data' => $data,
        'godziny' => array_column($godziny, 'godzina')]);
} catch (PDOException $wyjatek) {
    error_log('AJAX terminy: SQLSTATE ' . $wyjatek->getCode());
    terminyJson(503, ['ok' => false, 'komunikat' => 'Nie udało się pobrać terminów. Spróbuj ponownie.']);
} catch (RuntimeException | InvalidArgumentException $wyjatek) {
    terminyJson(422, ['ok' => false, 'komunikat' => $wyjatek->getMessage()]);
} catch (Throwable $wyjatek) {
    error_log('AJAX terminy: błąd typu ' . get_class($wyjatek));
    terminyJson(500, ['ok' => false, 'komunikat' => 'Nie udało się pobrać terminów. Spróbuj ponownie.']);
}
