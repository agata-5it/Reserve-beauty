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
require_once __DIR__ . '/../includes/rezerwacje.php';
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

    rezerwacjaZmienStatus($pdo, $klient, (int) $numer, 'anulowana');
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
