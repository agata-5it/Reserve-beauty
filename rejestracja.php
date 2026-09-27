<?php

session_start();

$komunikat = '';
$bledy = [];

$imie = '';
$nazwisko = '';
$email = '';
$telefon = '';

// Tworzymy losowy token chroniący formularz.
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Odczytujemy tylko wartości tekstowe.
function poleTekstowe(string $nazwa): string
{
    $wartosc = $_POST[$nazwa] ?? '';

    return is_string($wartosc) ? $wartosc : '';
}

// Przygotowujemy tekst do bezpiecznego wyświetlenia w HTML.
function e(string $tekst): string
{
    return htmlspecialchars(
        $tekst,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = poleTekstowe('csrf_token');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $bledy[] = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
    }

    // Pobieramy dane wpisane do formularza.
    $imie = trim(poleTekstowe('imie'));
    $nazwisko = trim(poleTekstowe('nazwisko'));
    $email = trim(poleTekstowe('email'));
    $telefon = trim(poleTekstowe('telefon'));

    $haslo = poleTekstowe('haslo');
    $powtorzHaslo = poleTekstowe('powtorz_haslo');

    // Sprawdzamy imię i nazwisko.
    if ($imie === '' || mb_strlen($imie) > 50) {
        $bledy[] = 'Imię musi mieć od 1 do 50 znaków.';
    }

    if ($nazwisko === '' || mb_strlen($nazwisko) > 50) {
        $bledy[] = 'Nazwisko musi mieć od 1 do 50 znaków.';
    }

    // Sprawdzamy adres e-mail.
    if (
        !filter_var($email, FILTER_VALIDATE_EMAIL)
        || mb_strlen($email) > 100
    ) {
        $bledy[] = 'Podaj poprawny adres e-mail, maksymalnie 100 znaków.';
    }

    // Usuwamy spacje i myślniki z numeru telefonu.
    $telefon = str_replace([' ', '-'], '', $telefon);

    // Przyjmujemy 9–15 cyfr, opcjonalnie poprzedzonych znakiem +.
    if (!preg_match('/^\+?[0-9]{9,15}$/', $telefon)) {
        $bledy[] = 'Telefon powinien zawierać od 9 do 15 cyfr, opcjonalnie z + na początku.';
    }

    // Sprawdzamy hasło przed utworzeniem jego hasha.
    if (
        mb_strlen($haslo) < 12
        || strlen($haslo) > 72
        || strpos($haslo, "\0") !== false
    ) {
        $bledy[] = 'Hasło musi mieć co najmniej 12 znaków i maksymalnie 72 bajty.';
    }

    if ($haslo !== $powtorzHaslo) {
        $bledy[] = 'Wpisane hasła nie są takie same.';
    }

    // Łączymy się z bazą tylko wtedy, gdy dane są poprawne.
    if (empty($bledy)) {
        try {
            require __DIR__ . '/includes/db.php';

            // Sprawdzamy, czy adres e-mail jest już zajęty.
            $zapytanie = $pdo->prepare(
                'SELECT id_uzytkownika
                 FROM uzytkownicy
                 WHERE email = :email
                 LIMIT 1'
            );

            $zapytanie->execute(['email' => $email]);

            if ($zapytanie->fetch()) {
                $bledy[] = 'Konto z tym adresem e-mail już istnieje.';
            } else {
                // Tworzymy hash — do bazy nie trafia jawne hasło.
                $hash = password_hash($haslo, PASSWORD_DEFAULT);

                $zapis = $pdo->prepare(
                    "INSERT INTO uzytkownicy
                        (imie, nazwisko, email, telefon, haslo,
                         rola, aktywny, utworzono_dnia)
                     VALUES
                        (:imie, :nazwisko, :email, :telefon, :haslo,
                         'klient', 1, NOW())"
                );

                $zapis->execute([
                    'imie' => $imie,
                    'nazwisko' => $nazwisko,
                    'email' => $email,
                    'telefon' => $telefon,
                    'haslo' => $hash,
                ]);

                // Zapamiętujemy komunikat na czas przekierowania.
                $_SESSION['rejestracja_sukces'] = true;

                header('Location: rejestracja.php');
                exit;
            }
        } catch (PDOException $e) {
            // Obsługujemy także duplikat wykryty przy samym zapisie.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $bledy[] = 'Nie można utworzyć konta: podane dane są już zajęte.';
            } else {
                // W logu zapisujemy kod błędu, bez danych formularza.
                error_log('Blad rejestracji. SQLSTATE: ' . $e->getCode());

                $bledy[] = 'Nie udało się utworzyć konta. Spróbuj ponownie później.';
            }
        }
    }
}

// Komunikat wyświetlany po udanym zapisie.
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && !empty($_SESSION['rejestracja_sukces'])
) {
    $komunikat = 'Konto zostało utworzone!';

    unset($_SESSION['rejestracja_sukces']);
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejestracja — Reserve Beauty</title>
</head>

<body>
    <main>
        <h1>Załóż konto w Reserve Beauty</h1>
        <p>Utwórz konto klienta, aby korzystać z rezerwacji.</p>

        <?php if ($komunikat !== ''): ?>
            <p role="status">
                <?= htmlspecialchars($komunikat, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>
        <?php if (!empty($bledy)): ?>
            <div role="alert">
                <p>Popraw następujące błędy:</p>

                <ul>
                    <?php foreach ($bledy as $blad): ?>
                        <li><?= e($blad) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form action="rejestracja.php" method="post">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>"
            >
            <p>
                <label for="imie">Imię</label><br>
                <input
                    type="text"
                    id="imie"
                    name="imie"
                    value="<?= e($imie) ?>"
                    autocomplete="given-name"
                    required
                >
            </p>

            <p>
                <label for="nazwisko">Nazwisko</label><br>
                <input
                    type="text"
                    id="nazwisko"
                    name="nazwisko"
                    value="<?= e($nazwisko) ?>"
                    autocomplete="family-name"
                    required
                >
            </p>

            <p>
                <label for="email">Adres e-mail</label><br>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= e($email) ?>"
                    autocomplete="email"
                    required
                >
            </p>

            <p>
                <label for="telefon">Numer telefonu</label><br>
                <input
                    type="tel"
                    id="telefon"
                    name="telefon"
                    value="<?= e($telefon) ?>"
                    autocomplete="tel"
                    required
                >
            </p>

            <p>
                <label for="haslo">Hasło</label><br>
                <input
                    type="password"
                    id="haslo"
                    name="haslo"
                    autocomplete="new-password"
                    required
                >
            </p>

            <p>
                <label for="powtorz_haslo">Powtórz hasło</label><br>
                <input
                    type="password"
                    id="powtorz_haslo"
                    name="powtorz_haslo"
                    autocomplete="new-password"
                    required
                >
            </p>

            <button type="submit">Załóż konto</button>
        </form>

    <p> Masz już konto? <a href="logowanie.php">Zaloguj się</a></p>
    </main>
</body>
</html>