<?php

require_once __DIR__ . '/../includes/auth.php';

$uzytkownik = wymagajRoli('admin');
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel administratora — Reserve Beauty</title>
</head>

<body>
    <main>
        <h1>Panel administratora</h1>

        <p>
            Witaj, <?= e($uzytkownik['imie']) ?>!
        </p>

        <p>
            Jesteś zalogowana lub zalogowany na konto:
            <?= e($uzytkownik['email']) ?>
        </p>

        <form
            action="<?= e(BASE_URL) ?>/wylogowanie.php"
            method="post"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>"
            >

            <button type="submit">Wyloguj się</button>
        </form>
    </main>
</body>
</html>