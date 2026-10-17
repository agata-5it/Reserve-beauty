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
    <link rel="stylesheet" href="../css/admin.css">
</head>

<body class="strona-konta">
    <nav class="admin-menu" aria-label="Menu administratora">
            <ul class="admin-menu_lista">
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/index.php" aria-current="page">Podsumowanie</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/kategorie.php">Kategorie</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/salony.php">Salony</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/uslugi.php">Usługi</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/pracownicy.php">Pracownicy</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/dostepnosc.php">Dostępność</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/urlopy.php">Urlopy</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/rezerwacje.php">Rezerwacje</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a>
                </li>
            </ul>
        </nav>
    <main>
        <div class="pole">
            <div>
                <p class="marka">RESERVE BEAUTY</p>
                <h1>Panel administratora</h1>
            </div>
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
        </div><br>

        <p>Witaj, <?= e($uzytkownik['imie']) ?>!</p>
        <p>Jesteś zalogowana lub zalogowany na konto:<?= e($uzytkownik['email']) ?></p>
        
    </main>
</body>
</html>