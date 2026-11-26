<?php
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    http_response_code(405);
    exit('Użyj przycisku zmiany statusu na liście swoich wizyt.');
}
require_once __DIR__ . '/../includes/auth.php';
$konto = wymagajRoli('pracownik');
require_once __DIR__ . '/../includes/rezerwacje.php';
header('Cache-Control: no-store');
$blad = '';
try {
    if (!sprawdzCsrf()) {
        throw new RuntimeException('Formularz wygasł. Odśwież listę i spróbuj ponownie.');
    }
    $tekst = $_POST['nr_rezerwacji'] ?? null;
    $nr = is_string($tekst) ? filter_var($tekst, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) : false;
    $nowy = $_POST['nowy'] ?? null;
    $poprzedni = $_POST['poprzedni'] ?? null;
    if (!$nr || !is_string($nowy) || !is_string($poprzedni)) {
        throw new RuntimeException('Nieprawidłowe dane formularza.');
    }
    rezerwacjaZmienStatus($pdo, $konto, (int) $nr, $nowy, $poprzedni);
    $statusy = ['potwierdzona' => 'Potwierdzona', 'anulowana' => 'Anulowana', 'zrealizowana' => 'Zrealizowana'];
    $_SESSION['pracownik_status_sukces'] = 'Rezerwacja nr ' . $nr . ' — nowy status: ' . $statusy[$nowy] . '. Zapisano historię zmiany.';
    unset($_SESSION['pracownik_status_blad']);
} catch (PDOException $wyjatek) {
    // Wspólna funkcja wykonała rollback zarówno statusu, jak i historii.
    $stan = (string) ($wyjatek->errorInfo[0] ?? $wyjatek->getCode());
    $kod = (int) ($wyjatek->errorInfo[1] ?? 0);
    error_log('Status pracownika: SQLSTATE ' . $stan . ', kod ' . $kod);
    if ($stan === '40001' || in_array($kod, [1205, 1213], true)) {
        $blad = 'Równocześnie zmieniano dane. Sprawdź aktualny status i spróbuj ponownie.';
    } elseif ($stan === '45000') {
        $blad = 'Baza zablokowała zmianę. Poproś administratora o sprawdzenie konfliktów i wyzwalaczy.';
    } else {
        $blad = 'Nie udało się zapisać statusu i historii. Spróbuj ponownie lub skontaktuj się z administratorem.';
    }
} catch (RuntimeException $wyjatek) {
    $blad = $wyjatek->getMessage();
} catch (Throwable $wyjatek) {
    error_log('Status pracownika: błąd typu ' . get_class($wyjatek));
    $blad = 'Nie udało się zapisać zmiany. Spróbuj ponownie.';
}
if ($blad !== '') {
    $_SESSION['pracownik_status_blad'] = $blad;
    unset($_SESSION['pracownik_status_sukces']);
}
header('Location: ' . BASE_URL . '/pracownik/rezerwacje.php', true, 303);
exit;
