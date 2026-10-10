<?php

require_once __DIR__ . '/includes/auth.php';

// Zmiana hasła jest dostępna tylko po zalogowaniu.
$uzytkownik = zalogowanyUzytkownik();

if ($uzytkownik === null) {
    przekieruj('/logowanie.php');
}

$idUzytkownika = (int) $uzytkownik['id_uzytkownika'];
$bledy = [];

// Hasła odczytujemy bez usuwania spacji.
function odczytajHaslo(string $nazwa): string
{
    $wartosc = $_POST[$nazwa] ?? '';

    return is_string($wartosc) ? $wartosc : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sprawdzCsrf()) {
        $bledy[] = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
    }

    $obecneHaslo = odczytajHaslo('obecne_haslo');
    $noweHaslo = odczytajHaslo('nowe_haslo');
    $powtorzHaslo = odczytajHaslo('powtorz_haslo');

    if (
        $obecneHaslo === ''
        || strlen($obecneHaslo) > 72
        || strpos($obecneHaslo, "\0") !== false
    ) {
        $bledy[] = 'Podaj poprawne obecne hasło.';
    }

    // Takie same zasady jak przy rejestracji.
    if (
        mb_strlen($noweHaslo) < 12
        || strlen($noweHaslo) > 72
        || strpos($noweHaslo, "\0") !== false
    ) {
        $bledy[] = 'Nowe hasło musi mieć co najmniej 12 znaków i maksymalnie 72 bajty.';
    }

    if ($noweHaslo !== $powtorzHaslo) {
        $bledy[] = 'Nowe hasła nie są takie same.';
    }

    if (empty($bledy)) {
        try {
            // Pobieramy hash hasła własnego konta.
            $zapytanie = $pdo->prepare(
                'SELECT haslo
                 FROM uzytkownicy
                 WHERE id_uzytkownika = :id
                   AND aktywny = 1
                 LIMIT 1'
            );

            $zapytanie->execute(['id' => $idUzytkownika]);
            $konto = $zapytanie->fetch();

            if (!$konto) {
                $bledy[] = 'Konto jest niedostępne. Zaloguj się ponownie.';
            } elseif (!password_verify($obecneHaslo, $konto['haslo'])) {
                $bledy[] = 'Obecne hasło jest nieprawidłowe.';
            } elseif (password_verify($noweHaslo, $konto['haslo'])) {
                $bledy[] = 'Nowe hasło musi być inne niż obecne.';
            } else {
                $nowyHash = password_hash(
                    $noweHaslo,
                    PASSWORD_DEFAULT
                );

                // Sprawdzamy również, czy hasło nie zmieniło się
                // w międzyczasie, np. w drugiej karcie.
                $zapis = $pdo->prepare(
                    'UPDATE uzytkownicy
                     SET haslo = :nowy_hash
                     WHERE id_uzytkownika = :id
                       AND BINARY haslo = :stary_hash
                       AND aktywny = 1'
                );

                $zapis->execute([
                    'nowy_hash' => $nowyHash,
                    'id' => $idUzytkownika,
                    'stary_hash' => $konto['haslo'],
                ]);

                if ($zapis->rowCount() === 1) {
                    // Czyścimy bieżące zalogowanie i wymieniamy identyfikator sesji.
                    $_SESSION = [];
                    session_regenerate_id(true);

                    $_SESSION['csrf_token'] =
                        bin2hex(random_bytes(32));

                    $_SESSION['haslo_zmienione'] = true;

                    przekieruj('/logowanie.php');
                } else {
                    $bledy[] = 'Dane konta zmieniły się w międzyczasie. Zaloguj się ponownie i spróbuj jeszcze raz.';
                }
            }
        } catch (PDOException $e) {
            error_log(
                'Blad zmiany hasla. SQLSTATE: ' . $e->getCode()
            );

            $bledy[] = 'Nie udało się zmienić hasła. Spróbuj później.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zmiana hasła — Reserve Beauty</title>
    <link rel="stylesheet" href="css/konto.css">
</head>

<body class="strona-konta">
    <main>
        <p class="marka">RESERVE BEAUTY</p>
        <h1>Zmień hasło</h1>

        <p>
            Podaj obecne hasło, a następnie wpisz nowe hasło dwa razy.
            Po zmianie zaloguj się ponownie.
        </p>

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

        <form action="zmiana-hasla.php" method="post">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>"
            >

            <p>
                <label for="obecne_haslo">Obecne hasło</label><br>
                <input
                    type="password"
                    id="obecne_haslo"
                    name="obecne_haslo"
                    autocomplete="current-password"
                    required
                >
            </p>

            <p>
                <label for="nowe_haslo">Nowe hasło</label><br>
                <input
                    type="password"
                    id="nowe_haslo"
                    name="nowe_haslo"
                    autocomplete="new-password"
                    aria-describedby="zasady-hasla"
                    required
                >
            </p>

            <p id="zasady-hasla">
                Co najmniej 12 znaków, maksymalnie 72 bajty.
                Polskie litery mogą zajmować więcej niż jeden bajt.
            </p>

            <p>
                <label for="powtorz_haslo">Powtórz nowe hasło</label><br>
                <input
                    type="password"
                    id="powtorz_haslo"
                    name="powtorz_haslo"
                    autocomplete="new-password"
                    required
                >
            </p>

            <button type="submit">Zmień hasło</button>
        </form>

        <p>
            <a href="<?= e(BASE_URL) ?>/profil.php">
                Wróć do profilu
            </a>
        </p>
    </main>
</body>
</html>