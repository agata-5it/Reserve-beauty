<?php
// Ten plik zapisuje rezerwację. Formularz znajduje się w rezerwuj.php.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Rezerwację zatwierdź przyciskiem „Potwierdzam rezerwację” w formularzu.');
}
require_once __DIR__ . '/../includes/auth.php';
$klient = wymagajRoli('klient');
require_once __DIR__ . '/../includes/rezerwacje.php';
header('Cache-Control: no-store');

// Zapis i podgląd korzystają z tego samego wyliczania dostępnych godzin.
// Przy zapisie odczyty są aktualne i odbywają się pod blokadami InnoDB.
function wyborZapisz(PDO $pdo, array $klient, array $oferta, string $uwagi): int
{
    $pdo->beginTransaction();
    try {
        // Wspólna kolejność z modułem urlopów: salon, potem pracownik.
        $q = $pdo->prepare('SELECT id_salonu FROM salony WHERE id_salonu = ? FOR UPDATE');
        $q->execute([$oferta['id_salonu']]);
        if (!$q->fetch()) {
            throw new RuntimeException('Salon nie jest już dostępny. Wybierz usługę ponownie.');
        }
        $q = $pdo->prepare('SELECT id_salonu FROM pracownicy WHERE id_pracownika = ? FOR UPDATE');
        $q->execute([$oferta['id_pracownika']]);
        if ((int) $q->fetchColumn() !== $oferta['id_salonu']) {
            throw new RuntimeException('Przypisanie pracownika do salonu zmieniło się. Wybierz pracownika ponownie.');
        }
        $q = $pdo->prepare(
            "SELECT us.cena, us.czas_trwania, us.id_salonu
             FROM uslugi us
             JOIN salony s ON s.id_salonu = us.id_salonu
             JOIN kategorie_uslug k ON k.id = us.id_kategoria
             JOIN pracownicy_uslugi pu ON pu.id_uslugi = us.id_uslugi
             JOIN pracownicy p ON p.id_pracownika = pu.id_pracownika AND p.id_salonu = us.id_salonu
             JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika
             WHERE us.id_uslugi = ? AND p.id_pracownika = ?
               AND us.aktywnosc = 1 AND s.aktywnosc = 1 AND k.aktywny = 1
               AND p.aktywnosc = 1 AND u.aktywny = 1 AND u.rola = 'pracownik'
             FOR UPDATE"
        );
        $q->execute([$oferta['id_uslugi'], $oferta['id_pracownika']]);
        $aktualna = $q->fetch(PDO::FETCH_ASSOC);
        if (!$aktualna || (int) $aktualna['id_salonu'] !== $oferta['id_salonu']) {
            throw new RuntimeException('Usługa nie jest już dostępna u tego pracownika w wybranym salonie.');
        }
        if (!preg_match('/\A[0-9]{1,8}\.[0-9]{2}\z/', (string) $aktualna['cena'])
            || (int) $aktualna['czas_trwania'] < 1 || (int) $aktualna['czas_trwania'] > 1440) {
            throw new RuntimeException('Usługa ma niepoprawną cenę lub czas trwania. Skontaktuj się z salonem.');
        }
        // Porównujemy z podsumowaniem zapisanym NA SERWERZE, nie z hidden input.
        if ((string) $aktualna['cena'] !== $oferta['cena']
            || (int) $aktualna['czas_trwania'] !== $oferta['czas_trwania']) {
            throw new RuntimeException('Cena lub czas usługi zmieniły się. Nie zapisano wizyty. Sprawdź aktualne warunki poniżej i potwierdź je ponownie.');
        }
        $q = $pdo->prepare("SELECT id_uzytkownika FROM uzytkownicy WHERE id_uzytkownika = ? AND aktywny = 1 AND rola = 'klient' FOR UPDATE");
        $q->execute([$klient['id_uzytkownika']]);
        if (!$q->fetch()) {
            throw new RuntimeException('Konto klienta nie jest już aktywne.');
        }
        $dzien = rezerwacjaData($oferta['data']);
        if (!$dzien) {
            throw new RuntimeException('Wybierz poprawną datę ponownie.');
        }
        $q = $pdo->prepare('SELECT czas_rozpoczecia, czas_zakonczenia FROM dostepnosc_pracownikow WHERE id_pracownika = ? AND dzien_tygodnia = ? ORDER BY czas_rozpoczecia FOR UPDATE');
        $q->execute([$oferta['id_pracownika'], (int) $dzien->format('N')]);
        $grafik = $q->fetchAll(PDO::FETCH_ASSOC);
        $q = $pdo->prepare("SELECT id_urlopu FROM urlopy WHERE id_pracownika = ? AND status = 'zaplanowany' AND data_od <= ? AND data_do >= ? FOR UPDATE");
        $q->execute([$oferta['id_pracownika'], $oferta['data'], $oferta['data']]);
        $urlop = count($q->fetchAll(PDO::FETCH_ASSOC)) > 0;
        $q = $pdo->prepare("SELECT data_rozpoczecia, data_zakonczenia FROM rezerwacje WHERE id_pracownika = ? AND status <> 'anulowana' AND data_rozpoczecia < ? AND data_zakonczenia > ? FOR UPDATE");
        $q->execute([$oferta['id_pracownika'], $dzien->modify('+1 day')->format('Y-m-d H:i:s'), $dzien->format('Y-m-d H:i:s')]);
        $wizyty = $q->fetchAll(PDO::FETCH_ASSOC);
        $start = rezerwacjaTermin($oferta['data'], $oferta['godzina']);
        $koniec = $start->add(new DateInterval('PT' . (int) $aktualna['czas_trwania'] . 'M'));
        if (rezerwacjaCzyKolizja($wizyty, $start->format('Y-m-d H:i:s'), $koniec->format('Y-m-d H:i:s'))) {
            throw new RuntimeException('Ten termin został już zajęty. Wybierz inną godzinę.');
        }
        $termin = null;
        foreach (rezerwacjaWyliczGodziny($oferta['data'], (int) $aktualna['czas_trwania'], $grafik, $wizyty, $urlop) as $wolny) {
            if ($wolny['godzina'] === $oferta['godzina']) {
                $termin = $wolny;
                break;
            }
        }
        if (!$termin) {
            throw new RuntimeException('Wybrany termin nie jest już dostępny. Wybierz inną godzinę.');
        }
        $q = $pdo->prepare(
            "INSERT INTO rezerwacje
             (id_uzytkownika, id_pracownika, id_uslugi, data_rozpoczecia, data_zakonczenia, cena, status, uwagi)
             VALUES (?, ?, ?, ?, ?, ?, 'oczekujaca', ?)"
        );
        $q->execute([$klient['id_uzytkownika'], $oferta['id_pracownika'], $oferta['id_uslugi'],
            $termin['data_rozpoczecia'], $termin['data_zakonczenia'], $aktualna['cena'], $uwagi === '' ? null : $uwagi]);
        $numer = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $numer;
    } catch (Throwable $wyjatek) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $wyjatek;
    }
}

// Z przeglądarki przyjmujemy token podsumowania oraz opcjonalne uwagi.
// Wybrane ID, termin i poprzednia cena są zapisane w sesji na serwerze.
$powrot = [];
$uwagi = '';
try {
    if (!sprawdzCsrf()) {
        throw new RuntimeException('Formularz wygasł. Wybierz usługę i termin ponownie.');
    }
    $token = $_POST['oferta_token'] ?? null;
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        throw new RuntimeException('Nieprawidłowe potwierdzenie. Wybierz usługę i termin ponownie.');
    }
    $oferta = $_SESSION['wybor_oferty'][$token] ?? null;
    if (!is_array($oferta) || ($oferta['id_klienta'] ?? null) !== (int) $klient['id_uzytkownika']
        || !is_int($oferta['wygasa'] ?? null) || $oferta['wygasa'] <= time()) {
        throw new RuntimeException('Podsumowanie wygasło lub zostało już wykorzystane. Wybierz usługę i termin ponownie.');
    }
    foreach (['id_salonu', 'id_pracownika', 'id_uslugi', 'czas_trwania'] as $pole) {
        if (!is_int($oferta[$pole] ?? null) || $oferta[$pole] < 1 || $oferta[$pole] > 2147483647) {
            throw new RuntimeException('Nieprawidłowe dane podsumowania. Wybierz usługę ponownie.');
        }
    }
    if (!is_string($oferta['data'] ?? null) || !is_string($oferta['godzina'] ?? null)
        || !rezerwacjaTermin($oferta['data'], $oferta['godzina'])
        || !is_string($oferta['cena'] ?? null)
        || !preg_match('/\A[0-9]{1,8}\.[0-9]{2}\z/', $oferta['cena'])
        || $oferta['czas_trwania'] > 1440) {
        throw new RuntimeException('Nieprawidłowe dane podsumowania. Wybierz termin ponownie.');
    }
    $powrot = ['id_uslugi' => $oferta['id_uslugi'], 'id_pracownika' => $oferta['id_pracownika'],
        'data' => $oferta['data'], 'godzina' => $oferta['godzina']];
    // Potwierdzenie jest jednorazowe. Po błędzie formularz utworzy nowe.
    unset($_SESSION['wybor_oferty'][$token]);
    if (!is_string($_POST['uwagi'] ?? '')) {
        throw new RuntimeException('Uwagi muszą być tekstem.');
    }
    $uwagi = trim($_POST['uwagi'] ?? '');
    if (strlen($uwagi) > 4000 || preg_match('//u', $uwagi) !== 1
        || preg_match_all('/./us', $uwagi) > 1000) {
        $uwagi = '';
        throw new RuntimeException('Uwagi mogą mieć maksymalnie 1000 znaków.');
    }
    $numer = wyborZapisz($pdo, $klient, $oferta, $uwagi);
    unset($_SESSION['zapis_rezerwacji_blad'], $_SESSION['zapis_rezerwacji_uwagi']);
    $_SESSION['wybor_sukces'] = 'Zapisano rezerwację nr ' . $numer . '. Status: oczekująca.';
    header('Location: ' . BASE_URL . '/klient/index.php#wizyty', true, 303);
    exit;
} catch (PDOException $wyjatek) {
    // wyborZapisz wycofuje transakcję również przy błędzie wyzwalacza.
    $stan = (string) ($wyjatek->errorInfo[0] ?? $wyjatek->getCode());
    $kod = (int) ($wyjatek->errorInfo[1] ?? 0);
    $tekst = (string) ($wyjatek->errorInfo[2] ?? '');
    error_log('Rezerwacja: zapis, SQLSTATE ' . $stan . ', kod ' . $kod);
    if ($stan === '45000' && (strpos($tekst, 'RB_TERMIN_ZAJETY') !== false
        || strpos($tekst, 'Ten pracownik wykonuje') !== false)) {
        $blad = 'Ten termin został już zajęty. Wybierz inną godzinę.';
    } elseif ($stan === '45000') {
        $blad = 'Termin jest niedostępny. Baza wykryła konflikt wizyty lub urlopu. Wybierz inny termin.';
    } elseif ($stan === '40001' || in_array($kod, [1205, 1213], true)) {
        $blad = 'Równocześnie zmieniano dane. Nie zapisano wizyty. Sprawdź termin i potwierdź ponownie.';
    } else {
        $blad = 'Nie udało się zapisać wizyty. Sprawdź podsumowanie i spróbuj ponownie.';
    }
} catch (RuntimeException $wyjatek) {
    $blad = $wyjatek->getMessage();
} catch (Throwable $wyjatek) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Rezerwacja: nieoczekiwany błąd typu ' . get_class($wyjatek));
    $blad = 'Nie udało się zapisać wizyty. Spróbuj ponownie.';
}
$_SESSION['zapis_rezerwacji_blad'] = $blad;
$_SESSION['zapis_rezerwacji_uwagi'] = $uwagi;
$adres = '/klient/rezerwuj.php' . ($powrot ? '?' . http_build_query($powrot) : '');
header('Location: ' . BASE_URL . $adres . '#podsumowanie', true, 303);
exit;
