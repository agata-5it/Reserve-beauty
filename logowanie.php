<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/includes/auth.php';

$blad = '';
$email = '';

// Zalogowana osoba od razu trafia do swojego panelu.
$uzytkownik = zalogowanyUzytkownik();

if ($uzytkownik !== null) {
    przekieruj(adresPanelu($uzytkownik['rola']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = is_string($_POST['email'] ?? null)
        ? trim($_POST['email'])
        : '';

    $haslo = is_string($_POST['haslo'] ?? null)
        ? $_POST['haslo']
        : '';

    if (!sprawdzCsrf()) {
        $blad = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
    } elseif (
        !filter_var($email, FILTER_VALIDATE_EMAIL)
        || mb_strlen($email) > 100
        || $haslo === ''
        || strlen($haslo) > 72
        || strpos($haslo, "\0") !== false
    ) {
        $blad = 'Podaj poprawny adres e-mail i hasło.';
    } else {
        try {
            $zapytanie = $pdo->prepare(
                'SELECT id_uzytkownika, haslo, rola, aktywny
                 FROM uzytkownicy
                 WHERE email = :email
                 LIMIT 1'
            );

            $zapytanie->execute(['email' => $email]);

            $konto = $zapytanie->fetch();

            if (
                $konto
                && password_verify($haslo, $konto['haslo'])
                && (int) $konto['aktywny'] === 1
                && in_array(
                    $konto['rola'],
                    ['klient', 'pracownik', 'admin'],
                    true
                )
            ) {
                // Po logowaniu nadajemy sesji nowy identyfikator.
                session_regenerate_id(true);

                $_SESSION['id_uzytkownika'] =
                    (int) $konto['id_uzytkownika'];

                // Wymieniamy także token formularzy.
                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));

                przekieruj(adresPanelu($konto['rola']));
            } else {
                $blad = 'Nie udało się zalogować. Sprawdź dane i upewnij się, że konto jest aktywne.';
            }
        } catch (PDOException $e) {
            error_log('Blad logowania. SQLSTATE: ' . $e->getCode());

            $blad = 'Logowanie jest chwilowo niedostępne. Spróbuj później.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logowanie — Reserve Beauty</title>
</head>

<body>
    <main>
        <h1>Zaloguj się do Reserve Beauty</h1>

        <?php if ($blad !== ''): ?>
            <p role="alert"><?= e($blad) ?></p>
        <?php endif; ?>

        <form action="logowanie.php" method="post">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>"
            >

            <p>
                <label for="email">Adres e-mail</label><br>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= e($email) ?>"
                    autocomplete="username"
                    maxlength="100"
                    required
                >
            </p>

            <p>
                <label for="haslo">Hasło</label><br>
                <input
                    type="password"
                    id="haslo"
                    name="haslo"
                    autocomplete="current-password"
                    required
                >
            </p>

            <button type="submit">Zaloguj się</button>
        </form>

        <p>
            Nie masz konta?
            <a href="rejestracja.php">Zarejestruj się</a>
        </p>
    </main>
</body>
</html>