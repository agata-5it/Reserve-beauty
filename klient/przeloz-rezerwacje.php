<?php
require_once __DIR__ . '/../includes/auth.php';
$klient = wymagajRoli('klient');
require_once __DIR__ . '/../includes/przekladanie.php';
header('Cache-Control: no-store');
$blad = $_SESSION['przelozenie_blad'] ?? '';
unset($_SESSION['przelozenie_blad']);
$numer = przelozId($_GET['nr_rezerwacji'] ?? null);
$data = is_string($_GET['data'] ?? null) ? $_GET['data'] : '';
$godzina = is_string($_GET['godzina'] ?? null) ? $_GET['godzina'] : '';
$w = null; $godziny = []; $termin = null; $token = '';
try {
    $w = przelozPobierz($pdo, (int) $klient['id_uzytkownika'], $numer);
    rezerwacjaUslugaPracownika($pdo, (int) $w['id_pracownika'], (int) $w['id_uslugi']);
    if ($data !== '') {
        $godziny = przelozGodziny($pdo, $w, $data);
        if ($godzina !== '') {
            foreach ($godziny as $t) { if ($t['godzina'] === $godzina) { $termin = $t; break; } }
            if (!$termin) { throw new RuntimeException('Wybierz inną, dostępną godzinę.'); }
            $_SESSION['przelozenia'] = $_SESSION['przelozenia'] ?? [];
            foreach ($_SESSION['przelozenia'] as $k => $v) { if ($v['wygasa'] < time()) unset($_SESSION['przelozenia'][$k]); }
            while (count($_SESSION['przelozenia']) >= 10) array_shift($_SESSION['przelozenia']);
            $token = bin2hex(random_bytes(32));
            $_SESSION['przelozenia'][$token] = ['stan' => przelozStan($w), 'data' => $data, 'godzina' => $godzina, 'wygasa' => time() + 1800];
        }
    }
} catch (PDOException $e) {
    error_log('Przełożenie: odczyt ' . $e->getCode());
    $blad = 'Nie udało się pobrać danych. Spróbuj ponownie.';
    $w = null;
} catch (RuntimeException $e) { $blad = $e->getMessage(); }
function przelozDataTekst(string $data): string {
    return (new DateTimeImmutable($data, rezerwacjaStrefa()))->format('d.m.Y H:i');
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Przełóż wizytę — Reserve Beauty</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/klient.css?v=3">
    <script src="<?= e(BASE_URL) ?>/js/rezerwacje.js?v=4" defer></script>
</head>
<body class="strona-klienta rezerwacja">
    <nav class="menu-klienta" aria-label="Menu klienta">
        <ul>
            <li><a href="<?= e(BASE_URL) ?>/klient/index.php">Podsumowanie</a></li>
            <li><a href="<?= e(BASE_URL) ?>/klient/rezerwacje.php">Moje rezerwacje</a></li>
            <li><a href="<?= e(BASE_URL) ?>/oferta.php" aria-current="page">Oferta usług</a></li>
            <li><a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a></li>
        </ul>
    </nav>
<main>
    <a href="<?= e(BASE_URL) ?>/klient/rezerwacje.php">← Moje rezerwacje</a>
    <section class="karta">
        <h1>Przełóż wizytę</h1>
        <?php if ($blad): ?><p class="blad" role="alert"><?= e($blad) ?></p><?php endif; ?>
        <?php if ($w): ?>
            <h2><?= e($w['usluga']) ?> — #<?= (int) $w['nr_rezerwacji'] ?></h2>
            <p><?= e($w['salon'] . ', ' . $w['miasto'] . ' — ' . $w['imie'] . ' ' . $w['nazwisko']) ?></p>
            <p>Dotychczasowy termin: <strong><?= e(przelozDataTekst($w['data_rozpoczecia'])) ?> – <?= e(przelozDataTekst($w['data_zakonczenia'])) ?></strong></p>
            <p>Cena pozostaje <?= e(number_format((float) $w['cena'], 2, ',', ' ')) ?> zł, czas wizyty: <?= (int) $w['czas_trwania'] ?> min.</p>
            <p class="pomoc">Wybierz nową datę i godzinę u tego samego pracownika. Po zmianie wizyta będzie oczekiwała na ponowne potwierdzenie. Dotychczasowy termin pozostaje zarezerwowany aż do poprawnego zapisu nowego.</p>
            <form id="wybor-terminu" method="get" action="<?= e(BASE_URL) ?>/klient/przeloz-rezerwacje.php#podsumowanie"
                data-terminy-url="<?= e(BASE_URL) ?>/klient/terminy-przelozenia.php" data-rezerwacja="<?= $numer ?>">
                <input type="hidden" name="nr_rezerwacji" value="<?= $numer ?>">
                <input type="hidden" name="id_uslugi" value="<?= (int) $w['id_uslugi'] ?>">
                <input type="radio" name="id_pracownika" value="<?= (int) $w['id_pracownika'] ?>" checked hidden aria-label="Obecny pracownik">
                        <section id="kalendarz-terminow" class="kalendarz-klienta" hidden aria-label="Kalendarz dostępnych dni">
                            <div class="kalendarz-nawigacja">
                                <button type="button" id="miesiac-wstecz" aria-label="Poprzedni miesiąc">←</button>
                                <h2 id="miesiac-tytul"></h2>
                                <button type="button" id="miesiac-dalej" aria-label="Następny miesiąc">→</button>
                            </div>
                            <p id="kalendarz-komunikat" role="status" aria-live="polite"></p>
                            <div class="kalendarz-tydzien" aria-hidden="true"><span>Pon</span><span>Wt</span><span>Śr</span><span>Czw</span><span>Pt</span><span>Sob</span><span>Nd</span></div>
                            <div id="kalendarz-dni" class="kalendarz-dni" aria-busy="false"></div>
                            <p class="kalendarz-legenda">Różowe dni: wolne godziny · Szare: niedostępne · Bordowy: wybrany dzień</p>
                            <button type="button" id="kalendarz-ponow" hidden>Spróbuj ponownie</button>
                        </section>
                <label>Nowa data wizyty<input type="date" name="data" min="<?= e(rezerwacjaTeraz()->format('Y-m-d')) ?>" max="9999-12-30" value="<?= e($data) ?>" required></label>
                <button type="submit">Pokaż wolne godziny</button>
                <div class="termin">
                    <h2>Wybierz nową godzinę</h2>
                    <p id="terminy-komunikat" role="status" aria-live="polite"><?= $data === '' ? 'Wybierz dzień.' : ($godziny ? 'Wybierz nową godzinę.' : 'Brak wolnych godzin w tym dniu.') ?></p>
                    <div id="terminy-godziny" class="akcje" aria-busy="false">
                        <?php foreach ($godziny as $t): ?>
                            <button type="submit" name="godzina" value="<?= e($t['godzina']) ?>"><?= e($t['godzina']) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </form>
            <?php if ($termin && $token): ?>
                <section class="termin" id="podsumowanie">
                    <h2>Potwierdź zmianę terminu</h2>
                    <p><?= e($w['usluga'] . ' — ' . $w['salon'] . ', ' . $w['miasto']) ?></p>
                    <p>Pracownik: <?= e($w['imie'] . ' ' . $w['nazwisko']) ?></p>
                    <p>Nowy termin: <strong><?= e(przelozDataTekst($termin['data_rozpoczecia'])) ?> – <?= e(przelozDataTekst($termin['data_zakonczenia'])) ?></strong></p>
                    <p>Cena: <?= e(number_format((float) $w['cena'], 2, ',', ' ')) ?> zł. Nowy status: oczekująca.</p>
                    <form method="post" action="<?= e(BASE_URL) ?>/klient/zapisz-przelozenie.php">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <button type="submit">Potwierdzam zmianę terminu</button>
                    </form>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
