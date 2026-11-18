<?php
// Zapis wykonujemy tylko przez formularz POST, nigdy przez link GET.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    http_response_code(405);
    exit('Aby anulować wizytę, użyj przycisku „Anuluj” na liście swoich rezerwacji.');
}

require_once __DIR__ . '/../includes/auth.php';
$klient = wymagajRoli('klient');
header('Cache-Control: no-store');
$idKlienta = (int) $klient['id_uzytkownika'];
$blad = '';

try {
    if (!sprawdzCsrf()) {
        throw new RuntimeException('Formularz wygasł. Odśwież listę rezerwacji i spróbuj ponownie.');
    }

    $wartosc = $_POST['nr_rezerwacji'] ?? null;
    $numer = is_string($wartosc) ? filter_var($wartosc, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) : false;
    if ($numer === false) {
        throw new RuntimeException('Nieprawidłowy numer rezerwacji.');
    }

    // Wstępny odczyt wskazuje pracownika do zablokowania.
    // Nie ujawniamy, czy podany numer należy do kogoś innego.
    $q = $pdo->prepare('SELECT id_pracownika FROM rezerwacje
        WHERE nr_rezerwacji = ? AND id_uzytkownika = ?');
    $q->execute([$numer, $idKlienta]);
    $idPracownika = (int) $q->fetchColumn();
    if (!$idPracownika) {
        throw new RuntimeException('Nie znaleziono tej rezerwacji na Twoim koncie.');
    }

    $pdo->beginTransaction();
    // Jak w zapisie wizyty: pracownik przed rezerwacją. Nie blokujemy salonu,
    // bo ta operacja wyłącznie zwalnia termin i nie zmienia salonu ani urlopów.
    $q = $pdo->prepare('SELECT id_pracownika FROM pracownicy WHERE id_pracownika = ? FOR UPDATE');
    $q->execute([$idPracownika]);
    if (!$q->fetch()) {
        throw new RuntimeException('Dane pracownika zmieniły się. Odśwież listę i spróbuj ponownie.');
    }

    // To jest aktualny odczyt pod blokadą, również po oczekiwaniu na inną transakcję.
    $q = $pdo->prepare('SELECT nr_rezerwacji, id_pracownika, status, data_rozpoczecia
        FROM rezerwacje WHERE nr_rezerwacji = ? AND id_uzytkownika = ? FOR UPDATE');
    $q->execute([$numer, $idKlienta]);
    $rezerwacja = $q->fetch(PDO::FETCH_ASSOC);
    if (!$rezerwacja) {
        throw new RuntimeException('Nie znaleziono tej rezerwacji na Twoim koncie.');
    }
    if ((int) $rezerwacja['id_pracownika'] !== $idPracownika) {
        throw new RuntimeException('Pracownik wizyty został zmieniony. Odśwież listę i spróbuj ponownie.');
    }
    if (!in_array($rezerwacja['status'], ['oczekujaca', 'potwierdzona'], true)) {
        throw new RuntimeException('Możesz anulować tylko rezerwację oczekującą lub potwierdzoną. Ta wizyta ma już inny status.');
    }

    // Czas ustalamy PO uzyskaniu blokad, aby uwzględnić ewentualne oczekiwanie.
    $teraz = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d H:i:s');
    if ($rezerwacja['data_rozpoczecia'] <= $teraz) {
        throw new RuntimeException('Nie można anulować wizyty, która już się rozpoczęła lub minęła.');
    }

    // Zachowujemy cały rekord, jego cenę, godziny i uwagi. Zmieniamy tylko status.
    $q = $pdo->prepare("UPDATE rezerwacje SET status = 'anulowana'
        WHERE nr_rezerwacji = ? AND id_uzytkownika = ?
          AND status = ? AND data_rozpoczecia > ?");
    $q->execute([$numer, $idKlienta, $rezerwacja['status'], $teraz]);
    if ($q->rowCount() !== 1) {
        throw new RuntimeException('Nie udało się anulować wizyty. Odśwież listę i sprawdź jej aktualny status.');
    }
    $pdo->commit();
    $_SESSION['anulowanie_sukces'] = 'Anulowano rezerwację nr ' . $numer . '. Wizyta pozostała w historii, a termin został zwolniony.';
    unset($_SESSION['anulowanie_blad']);
} catch (PDOException $wyjatek) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $stan = (string) ($wyjatek->errorInfo[0] ?? $wyjatek->getCode());
    $kod = (int) ($wyjatek->errorInfo[1] ?? 0);
    error_log('Anulowanie rezerwacji: SQLSTATE ' . $stan . ', kod ' . $kod);
    if ($stan === '40001' || in_array($kod, [1205, 1213], true)) {
        $blad = 'W tym samym czasie zmieniano dane wizyty. Odśwież listę i spróbuj ponownie.';
    } elseif ($stan === '45000') {
        $blad = 'Baza zablokowała anulowanie. Skontaktuj się z administratorem, aby sprawdził wyzwalacze rezerwacji.';
    } else {
        $blad = 'Nie udało się anulować rezerwacji. Spróbuj ponownie za chwilę.';
    }
} catch (RuntimeException $wyjatek) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $blad = $wyjatek->getMessage();
} catch (Throwable $wyjatek) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Anulowanie rezerwacji: błąd typu ' . get_class($wyjatek));
    $blad = 'Nie udało się anulować rezerwacji. Spróbuj ponownie za chwilę.';
}

if ($blad !== '') {
    $_SESSION['anulowanie_blad'] = $blad;
    unset($_SESSION['anulowanie_sukces']);
}
// Po POST wracamy przez GET. Odświeżenie listy nie ponawia anulowania.
header('Location: ' . BASE_URL . '/klient/rezerwacje.php', true, 303);
exit;
