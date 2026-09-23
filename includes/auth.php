<?php

// Ustawienia muszą być przed uruchomieniem sesji.
ini_set('session.use_strict_mode', '1');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off',
]);

session_start();

// Adres folderu aplikacji w przeglądarce.
const BASE_URL = '/reserve-beauty';

// Połączenie z bazą.
try {
    require __DIR__ . '/db.php';
} catch (PDOException $e) {
    error_log('Blad polaczenia. SQLSTATE: ' . $e->getCode());

    http_response_code(503);
    exit('Nie można teraz połączyć się z aplikacją. Spróbuj później.');
}

// Bezpieczne wyświetlanie tekstu w HTML.
function e(string $tekst): string
{
    return htmlspecialchars(
        $tekst,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Przekierowanie na inną stronę.
function przekieruj(string $sciezka): void
{
    header('Location: ' . BASE_URL . $sciezka);
    exit;
}

// Adres panelu dla wskazanej roli.
function adresPanelu(string $rola): string
{
    switch ($rola) {
        case 'klient':
            return '/klient/index.php';

        case 'pracownik':
            return '/pracownik/index.php';

        case 'admin':
            return '/admin/index.php';

        default:
            http_response_code(403);
            exit('Konto nie ma poprawnie ustawionej roli.');
    }
}

// Token chroniący formularze.
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function sprawdzCsrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';

    return is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

// Pobranie aktualnych danych zalogowanej osoby.
function zalogowanyUzytkownik(): ?array
{
    global $pdo;

    $id = $_SESSION['id_uzytkownika'] ?? null;

    if (!is_int($id) || $id < 1) {
        return null;
    }

    try {
        $zapytanie = $pdo->prepare(
            'SELECT id_uzytkownika, imie, nazwisko, email, rola, aktywny
             FROM uzytkownicy
             WHERE id_uzytkownika = :id
             LIMIT 1'
        );

        $zapytanie->execute(['id' => $id]);
        $uzytkownik = $zapytanie->fetch();
    } catch (PDOException $e) {
        error_log('Blad odczytu konta. SQLSTATE: ' . $e->getCode());

        http_response_code(503);
        exit('Nie można teraz sprawdzić konta. Spróbuj później.');
    }

    if (!$uzytkownik || (int) $uzytkownik['aktywny'] !== 1) {
        unset($_SESSION['id_uzytkownika']);

        return null;
    }

    return $uzytkownik;
}

// Ochrona panelu przed osobami bez odpowiedniej roli.
function wymagajRoli(string $wymaganaRola): array
{
    $uzytkownik = zalogowanyUzytkownik();

    if ($uzytkownik === null) {
        przekieruj('/logowanie.php');
    }

    if ($uzytkownik['rola'] !== $wymaganaRola) {
        http_response_code(403);
        exit('Nie masz uprawnień do tej strony.');
    }

    return $uzytkownik;
}