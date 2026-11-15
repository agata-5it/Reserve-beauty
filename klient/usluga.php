<?php
// Publiczna strona — nie wymaga zalogowania.
require_once __DIR__ . '/includes/auth.php';

$uzytkownik = zalogowanyUzytkownik();
$usluga = null;
$blad = '';
$wartoscId = $_GET['id'] ?? '';
$id = is_string($wartoscId) ? filter_var($wartoscId, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 2147483647]
]) : false;

if ($id === false) {
    http_response_code(400);
    $blad = 'Nieprawidłowy numer usługi. Wybierz usługę z naszej oferty.';
} else {
    try {
        // Dostępna musi być usługa, jej kategoria oraz salon.
        $zapytanie = $pdo->prepare(
            'SELECT u.id_uslugi, u.nazwa, u.opis, u.cena, u.czas_trwania,
                    k.nazwa AS kategoria, s.nazwa AS salon, s.miasto,
                    s.ulica, s.nr_ulicy
             FROM uslugi u
             JOIN kategorie_uslug k ON k.id = u.id_kategoria
             JOIN salony s ON s.id_salonu = u.id_salonu
             WHERE u.id_uslugi = ? AND u.aktywnosc = 1
               AND k.aktywny = 1 AND s.aktywnosc = 1
             LIMIT 1'
        );
        $zapytanie->execute([$id]);
        $usluga = $zapytanie->fetch(PDO::FETCH_ASSOC);
        if (!$usluga) {
            http_response_code(404);
            $blad = 'Ta usługa nie istnieje lub nie jest obecnie dostępna. Sprawdź pozostałe usługi w naszej ofercie.';
        }
    } catch (PDOException $wyjatek) {
        http_response_code(503);
        error_log('Szczegóły usługi: SQLSTATE ' . $wyjatek->getCode());
        $blad = 'Nie udało się pobrać usługi. Spróbuj ponownie za chwilę.';
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $usluga ? e($usluga['nazwa']) : 'Szczegóły usługi' ?> — Reserve Beauty</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/klient.css?v=2">
</head>
<body class="strona-klienta usluga">
    <?php $stronaMenu = 'usluga'; require __DIR__ . '/includes/menu-klienta.php'; ?>
    <main>
        <a class="powrot" href="<?= e(BASE_URL) ?>/oferta.php">← Wróć do oferty</a>
        <?php if ($blad): ?>
            <section class="karta">
                <h1>Nie możemy wyświetlić usługi</h1>
                <p role="alert"><?= e($blad) ?></p>
                <a class="przycisk" href="<?= e(BASE_URL) ?>/oferta.php">Zobacz ofertę</a>
            </section>
        <?php else: ?>
            <article class="karta uklad">
                <div>
                    <span class="kategoria"><?= e($usluga['kategoria']) ?></span>
                    <h1><?= e($usluga['nazwa']) ?></h1>
                    <p><?= e($usluga['salon'] . ' — ' . $usluga['miasto']) ?></p>
                    <h2>O usłudze</h2>
                    <p class="opis"><?= e(trim((string) $usluga['opis']) !== '' ? $usluga['opis'] : 'Opis tej usługi nie został jeszcze dodany.') ?></p>
                </div>
                <aside class="podsumowanie" aria-label="Cena i miejsce wizyty">
                    <dl>
                        <dt>Cena</dt>
                        <dd class="cena"><?= e(number_format((float) $usluga['cena'], 2, ',', ' ')) ?> zł</dd>
                        <dt>Czas trwania</dt>
                        <dd><?= (int) $usluga['czas_trwania'] ?> min</dd>
                        <dt>Salon</dt>
                        <dd><?= e($usluga['salon']) ?></dd>
                        <dt>Adres</dt>
                        <dd><?= e(trim($usluga['ulica'] . ' ' . $usluga['nr_ulicy'])) ?><br><?= e($usluga['miasto']) ?></dd>
                    </dl>
                    <?php if (!$uzytkownik): ?>
                        <a class="przycisk" href="<?= e(BASE_URL) ?>/logowanie.php">Zaloguj się, aby zarezerwować</a>
                        <p class="pomoc">Rezerwacje są dostępne dla zalogowanych klientów. Po zalogowaniu wróć do tej usługi w ofercie.</p>
                    <?php elseif ($uzytkownik['rola'] === 'klient'): ?>
                        <!-- Formularz rezerwacji powinien odczytać parametr id_uslugi. -->
                        <a class="przycisk" href="<?= e(BASE_URL) ?>/klient/rezerwuj.php?id_uslugi=<?= (int) $usluga['id_uslugi'] ?>">Zarezerwuj</a>
                        <p class="pomoc">W kolejnym kroku wybierzesz pracownika i dostępny termin.</p>
                    <?php else: ?>
                        <p class="pomoc">Rezerwowanie wizyt w ofercie jest dostępne na koncie klienta. Jesteś zalogowana lub zalogowany na koncie pracownika albo administratora.</p>
                    <?php endif; ?>
                </aside>
            </article>
        <?php endif; ?>
    </main>
</body>
</html>