<?php
require_once __DIR__ . '/includes/auth.php';

// Strona główna jest publiczna. Konto służy tylko do dopasowania menu.
$konto = zalogowanyUzytkownik();
$rola = $konto['rola'] ?? '';
$nazwyPaneli = [
    'klient' => 'Panel klienta',
    'pracownik' => 'Panel pracownika',
    'admin' => 'Panel administratora',
];
$maPanel = $konto && isset($nazwyPaneli[$rola]);
$panel = $maPanel ? adresPanelu($rola) : '/logowanie.php';
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Poznaj ofertę Reserve Beauty. Wybierz usługę, pracownika i dostępny termin wizyty, a następnie zarządzaj swoimi rezerwacjami online.">
    <title>Reserve Beauty — rezerwacja wizyt online</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/klient.css">
</head>
<body class="strona-klienta">
    <nav class="menu-klienta" aria-label="Menu główne">
        <ul>
            <li><a href="<?= e(BASE_URL) ?>/index.php" aria-current="page">Strona główna</a></li>
            <li><a href="<?= e(BASE_URL) ?>/oferta.php">Oferta usług</a></li>
            <?php if ($maPanel): ?>
                <li><a href="<?= e(BASE_URL . $panel) ?>"><?= e($nazwyPaneli[$rola]) ?></a></li>
                <?php if ($rola === 'klient'): ?>
                    <li><a href="<?= e(BASE_URL) ?>/klient/rezerwacje.php">Moje rezerwacje</a></li>
                <?php endif; ?>
                <li><a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a></li>
                <li class="wylogowanie">
                    <form method="post" action="<?= e(BASE_URL) ?>/wylogowanie.php">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <button type="submit">Wyloguj się</button>
                    </form>
                </li>
            <?php else: ?>
                <li><a href="<?= e(BASE_URL) ?>/logowanie.php">Zaloguj się</a></li>
                <li><a href="<?= e(BASE_URL) ?>/rejestracja.php">Załóż konto</a></li>
            <?php endif; ?>
        </ul>
    </nav>

    <main>
        <section class="pole" aria-labelledby="powitanie">
            <div>
                <p class="marka">Reserve Beauty</p>
                <h1 id="powitanie">Chwila dla Ciebie.<br>Termin na Twoich zasadach.</h1>
                <p>Znajdź usługę w wybranym salonie, poznaj dostępnych pracowników
                    i wybierz dogodną godzinę wizyty.</p>
                <div class="akcje">
                    <a class="przycisk" href="<?= e(BASE_URL) ?>/oferta.php">Przeglądaj ofertę</a>
                    <?php if ($maPanel): ?>
                        <a href="<?= e(BASE_URL . $panel) ?>">Przejdź do swojego panelu</a>
                    <?php else: ?>
                        <a href="<?= e(BASE_URL) ?>/rejestracja.php">Załóż konto klienta</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="karta" aria-labelledby="jak-rezerwowac">
            <h2 id="jak-rezerwowac">Twoja wizyta w trzech krokach</h2>
            <p class="pomoc">Ofertę możesz przeglądać bez logowania. Do zapisania wizyty potrzebujesz konta klienta.</p>
            <div class="lista-uslug">
                <article class="karta-uslugi">
                    <span class="kategoria">Krok 1</span>
                    <h3>Wybierz usługę</h3>
                    <p>Przefiltruj ofertę według salonu i kategorii. Sprawdź opis zabiegu, cenę oraz czas trwania.</p>
                </article>
                <article class="karta-uslugi">
                    <span class="kategoria">Krok 2</span>
                    <h3>Znajdź wolny termin</h3>
                    <p>Wybierz pracownika wykonującego usługę, dzień w kalendarzu i jedną z dostępnych godzin.</p>
                </article>
                <article class="karta-uslugi">
                    <span class="kategoria">Krok 3</span>
                    <h3>Potwierdź rezerwację</h3>
                    <p>Sprawdź podsumowanie i zapisz wizytę. Jej aktualny status zobaczysz na liście swoich rezerwacji.</p>
                </article>
            </div>
        </section>

        <section class="pole" aria-labelledby="twoje-wizyty">
            <div>
                <?php if ($maPanel && $rola !== 'klient'): ?>
                    <h2 id="twoje-wizyty"><?= e($nazwyPaneli[$rola]) ?></h2>
                    <p>Witaj, <?= e($konto['imie']) ?>. Przejdź do panelu, aby sprawdzić wizyty i zarządzać swoją częścią systemu.</p>
                <?php else: ?>
                    <h2 id="twoje-wizyty">Wszystkie Twoje wizyty w jednym miejscu</h2>
                    <p>Sprawdzaj nadchodzące wizyty, ich statusy i historię.
                        Jeśli plany się zmienią, przełóż lub anuluj przyszłą wizytę oczekującą albo potwierdzoną.</p>
                <?php endif; ?>
            </div>
            <?php if ($maPanel && $rola === 'klient'): ?>
                <a class="przycisk" href="<?= e(BASE_URL) ?>/klient/rezerwacje.php">Moje rezerwacje</a>
            <?php elseif ($maPanel): ?>
                <a class="przycisk" href="<?= e(BASE_URL . $panel) ?>">Otwórz panel</a>
            <?php else: ?>
                <a class="przycisk" href="<?= e(BASE_URL) ?>/logowanie.php">Zaloguj się</a>
            <?php endif; ?>
        </section>
    </main>
    <footer>Reserve Beauty · System rezerwacji usług beauty</footer>
</body>
</html>
