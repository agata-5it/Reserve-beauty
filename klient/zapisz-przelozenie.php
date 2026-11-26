<?php
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); http_response_code(405); exit('Użyj formularza zmiany terminu.');
}
require_once __DIR__ . '/../includes/auth.php';
$klient = wymagajRoli('klient');
require_once __DIR__ . '/../includes/przekladanie.php';
header('Cache-Control: no-store');
$numer = 0;
try {
    if (!sprawdzCsrf()) { throw new RuntimeException('Formularz wygasł. Otwórz zmianę terminu ponownie.'); }
    $token = $_POST['token'] ?? null;
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        throw new RuntimeException('Nieprawidłowe potwierdzenie zmiany.');
    }
    $oferta = $_SESSION['przelozenia'][$token] ?? null;
    if (!is_array($oferta) || $oferta['wygasa'] <= time()
        || (int) $oferta['stan']['id_uzytkownika'] !== (int) $klient['id_uzytkownika']) {
        throw new RuntimeException('Podsumowanie wygasło lub zostało wykorzystane. Otwórz zmianę terminu ponownie.');
    }
    unset($_SESSION['przelozenia'][$token]);
    $numer = (int) $oferta['stan']['nr_rezerwacji'];
    if (!rezerwacjaTermin($oferta['data'], $oferta['godzina'])) { throw new RuntimeException('Nieprawidłowy termin.'); }
    przelozZapisz($pdo, $klient, $oferta);
    $_SESSION['wybor_sukces'] = 'Zmieniono termin wizyty nr ' . $numer . '. Wizyta oczekuje na ponowne potwierdzenie.';
    header('Location: ' . BASE_URL . '/klient/rezerwacje.php', true, 303); exit;
} catch (PDOException $e) {
    error_log('Przełożenie: zapis ' . $e->getCode());
    $blad = 'Nie udało się zmienić terminu — wystąpił konflikt lub błąd bazy. Dotychczasowa wizyta pozostaje bez zmian. Odśwież i spróbuj ponownie.';
} catch (RuntimeException $e) { $blad = $e->getMessage();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Przełożenie: ' . get_class($e));
    $blad = 'Nie zapisano zmiany. Otwórz formularz ponownie.';
}
if ($numer) {
    $_SESSION['przelozenie_blad'] = $blad;
    header('Location: ' . BASE_URL . '/klient/przeloz-rezerwacje.php?nr_rezerwacji=' . $numer, true, 303);
} else {
    $_SESSION['anulowanie_blad'] = $blad;
    header('Location: ' . BASE_URL . '/klient/rezerwacje.php', true, 303);
}
exit;
