<?php

require_once __DIR__ . '/bootstrap.php';

// Połączenie z bazą.
try {
    require_once __DIR__ . '/db.php';
} catch (PDOException $e) {
    error_log('Blad polaczenia. SQLSTATE: ' . $e->getCode());

    http_response_code(503);
    exit('Nie można teraz połączyć się z aplikacją. Spróbuj później.');
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
            'SELECT id_uzytkownika, imie, nazwisko, email, telefon, rola, aktywny
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
