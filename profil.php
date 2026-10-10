<?php

require_once __DIR__ . '/includes/auth.php';

// Sprawdzamy, kto jest zalogowany.
$uzytkownik = zalogowanyUzytkownik();

if ($uzytkownik === null) {
    przekieruj('/logowanie.php');
}

// Identyfikator pochodzi z zalogowanego konta,
// a nie z formularza lub adresu strony.
$idUzytkownika = (int) $uzytkownik['id_uzytkownika'];

$bledy = [];
$komunikat = '';

// Początkowo formularz pokazuje dane zapisane w bazie.
$imie = $uzytkownik['imie'];
$nazwisko = $uzytkownik['nazwisko'];
$email = $uzytkownik['email'];
$telefon = $uzytkownik['telefon'] ?? '';

// Odczytujemy wyłącznie wartości tekstowe.
function poleProfilu(string $nazwa): string
{
    $wartosc = $_POST[$nazwa] ?? '';

    return is_string($wartosc) ? trim($wartosc) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sprawdzCsrf()) {
        $bledy[] = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
    }

    // Odczytujemy nowe dane z formularza.
    $imie = poleProfilu('imie');
    $nazwisko = poleProfilu('nazwisko');
    $email = poleProfilu('email');
    $telefon = poleProfilu('telefon');

    // Sprawdzamy dane przed zapisem.
    if ($imie === '' || mb_strlen($imie) > 50) {
        $bledy[] = 'Imię musi mieć od 1 do 50 znaków.';
    }

    if ($nazwisko === '' || mb_strlen($nazwisko) > 50) {
        $bledy[] = 'Nazwisko musi mieć od 1 do 50 znaków.';
    }

    if (
        !filter_var($email, FILTER_VALIDATE_EMAIL)
        || mb_strlen($email) > 100
    ) {
        $bledy[] = 'Podaj poprawny adres e-mail, maksymalnie 100 znaków.';
    }

    $telefon = str_replace([' ', '-'], '', $telefon);

    if (!preg_match('/^\+?[0-9]{9,15}$/', $telefon)) {
        $bledy[] = 'Telefon powinien zawierać od 9 do 15 cyfr, opcjonalnie z + na początku.';
    }

    if (empty($bledy)) {
        try {
            // Sprawdzamy, czy e-mail należy do INNEGO konta.
            $sprawdzenie = $pdo->prepare(
                'SELECT id_uzytkownika
                 FROM uzytkownicy
                 WHERE email = :email
                   AND id_uzytkownika <> :id
                 LIMIT 1'
            );

            $sprawdzenie->execute([
                'email' => $email,
                'id' => $idUzytkownika,
            ]);

            if ($sprawdzenie->fetch()) {
                $bledy[] = 'Ten adres e-mail jest już używany przez inne konto.';
            } else {
                // Zmieniamy wyłącznie dozwolone dane własnego konta.
                $zapis = $pdo->prepare(
                    'UPDATE uzytkownicy
                     SET imie = :imie,
                         nazwisko = :nazwisko,
                         email = :email,
                         telefon = :telefon
                     WHERE id_uzytkownika = :id'
                );

                $zapis->execute([
                    'imie' => $imie,
                    'nazwisko' => $nazwisko,
                    'email' => $email,
                    'telefon' => $telefon,
                    'id' => $idUzytkownika,
                ]);

                $_SESSION['profil_sukces'] = true;

                przekieruj('/profil.php');
            }
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $bledy[] = 'Nie można zapisać zmian: podane dane są już zajęte.';
            } else {
                error_log(
                    'Blad zapisu profilu. SQLSTATE: ' . $e->getCode()
                );

                $bledy[] = 'Nie udało się zapisać zmian. Spróbuj później.';
            }
        }
    }
}

// Jednorazowy komunikat po udanym zapisie.
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && !empty($_SESSION['profil_sukces'])
) {
    $komunikat = 'Dane zostały zapisane.';

    unset($_SESSION['profil_sukces']);
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mój profil — Reserve Beauty</title>
    <link rel="stylesheet" href="css/konto.css">
</head>

<body class="strona-konta">
    <main>
        <p class="marka">RESERVE BEAUTY</p>
        <h1>Mój profil</h1>
        <p>Tutaj możesz zmienić swoje dane kontaktowe.</p>

        <?php if ($komunikat !== ''): ?>
            <p role="status"><?= e($komunikat) ?></p>
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

        <form action="profil.php" method="post">
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
                    maxlength="50"
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
                    maxlength="50"
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
                    maxlength="100"
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
                    maxlength="20"
                    autocomplete="tel"
                    required
                >
            </p>

            <button type="submit">Zapisz zmiany</button>
        </form>

        <p>
            <a href="<?= e(BASE_URL) ?>/zmiana-hasla.php">
                Zmień hasło
            </a>
        </p>

        <p>
            <a href="<?= e(BASE_URL . adresPanelu($uzytkownik['rola'])) ?>">
                Wróć do panelu
            </a>
        </p>
    </main>
</body>
</html>