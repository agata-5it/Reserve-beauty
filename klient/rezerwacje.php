<?php
require_once __DIR__ . '/../includes/auth.php';
$klient = wymagajRoli('klient');
header('Cache-Control: no-store');

// Właściciel listy pochodzi wyłącznie z zalogowanego konta.
$idKlienta = (int) $klient['id_uzytkownika'];
$teraz = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d H:i:s');
$statusy = ['oczekujaca' => 'Oczekująca', 'potwierdzona' => 'Potwierdzona',
    'zrealizowana' => 'Zrealizowana', 'anulowana' => 'Anulowana'];
$blad = '';
$komunikat = $_SESSION['wybor_sukces'] ?? '';
unset($_SESSION['wybor_sukces']);

function listaData(string $data): string
{
    return (new DateTimeImmutable($data, new DateTimeZone('Europe/Warsaw')))->format('d.m.Y H:i');
}

function listaStrona(string $pole): int
{
    $wartosc = $_GET[$pole] ?? '1';
    if (!is_string($wartosc)) { return 1; }
    return (int) (filter_var($wartosc, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 1000000]]) ?: 1);
}

$sekcje = [
    'nadchodzace' => ['tytul' => 'Nadchodzące wizyty',
        'opis' => 'Oczekujące i potwierdzone wizyty, które jeszcze się nie zakończyły — również trwające.',
        'pusto' => 'Nie masz nadchodzących wizyt.', 'strona' => listaStrona('nadchodzace')],
    'historia' => ['tytul' => 'Historia rezerwacji',
        'opis' => 'Wizyty anulowane, zrealizowane oraz te, których termin już minął. Upływ czasu nie zmienia ich statusu.',
        'pusto' => 'Historia rezerwacji jest jeszcze pusta.', 'strona' => listaStrona('historia')]
];

try {
    foreach ($sekcje as $klucz => &$sekcja) {
        // Fragmenty SQL są stałe — nie pochodzą z adresu ani formularza.
        $warunek = $klucz === 'nadchodzace'
            ? "r.status IN ('oczekujaca', 'potwierdzona') AND r.data_zakonczenia > ?"
            : "(r.status NOT IN ('oczekujaca', 'potwierdzona') OR r.data_zakonczenia <= ?)";
        $q = $pdo->prepare('SELECT COUNT(*) FROM rezerwacje r WHERE r.id_uzytkownika = ? AND ' . $warunek);
        $q->execute([$idKlienta, $teraz]);
        $sekcja['liczba'] = (int) $q->fetchColumn();
        $sekcja['strony'] = max(1, (int) ceil($sekcja['liczba'] / 10));
        $sekcja['strona'] = min($sekcja['strona'], $sekcja['strony']);
        $offset = ($sekcja['strona'] - 1) * 10;
        $kolejnosc = $klucz === 'nadchodzace' ? 'ASC' : 'DESC';
        // Nie filtrujemy aktywności usługi: historyczna wizyta ma pozostać widoczna.
        // Cena i obie godziny pochodzą z rezerwacji, nie z aktualnej oferty.
        $q = $pdo->prepare(
            'SELECT r.nr_rezerwacji, r.data_rozpoczecia, r.data_zakonczenia,
                    r.cena, r.status, r.uwagi, us.nazwa AS usluga,
                    s.nazwa AS salon, s.miasto, u.imie, u.nazwisko
             FROM rezerwacje r
             LEFT JOIN uslugi us ON us.id_uslugi = r.id_uslugi
             LEFT JOIN salony s ON s.id_salonu = us.id_salonu
             LEFT JOIN pracownicy p ON p.id_pracownika = r.id_pracownika
             LEFT JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika
             WHERE r.id_uzytkownika = ? AND ' . $warunek . '
             ORDER BY r.data_rozpoczecia ' . $kolejnosc . ', r.nr_rezerwacji ' . $kolejnosc . '
             LIMIT 10 OFFSET ' . $offset
        );
        $q->execute([$idKlienta, $teraz]);
        $sekcja['wizyty'] = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($sekcja);
} catch (PDOException $wyjatek) {
    unset($sekcja);
    error_log('Lista rezerwacji klienta: SQLSTATE ' . $wyjatek->getCode());
    http_response_code(503);
    $blad = 'Nie udało się pobrać Twoich rezerwacji. Odśwież stronę, aby spróbować ponownie.';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Moje rezerwacje — Reserve Beauty</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/klient.css?v=2">
</head>
<body class="strona-klienta panel-klienta">
    <nav class="menu-klienta" aria-label="Menu klienta">
        <ul>
            <li><a href="<?= e(BASE_URL) ?>/klient/index.php">Podsumowanie</a></li>
            <li><a href="<?= e(BASE_URL) ?>/klient/rezerwacje.php" aria-current="page">Moje rezerwacje</a></li>
            <li><a href="<?= e(BASE_URL) ?>/oferta.php">Oferta usług</a></li>
            <li><a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a></li>
        </ul>
    </nav>
    <main>
        <header class="pole">
            <div>
                <p class="marka">RESERVE BEAUTY</p>
                <h1>Moje rezerwacje</h1>
                <p>Twoje nadchodzące wizyty i historia w jednym miejscu.</p>
            </div>
            <a class="przycisk" href="<?= e(BASE_URL) ?>/oferta.php">Zarezerwuj wizytę</a>
        </header>
        <?php if ($komunikat): ?>
            <p class="sukces" role="status"><?= e($komunikat) ?></p>
        <?php endif; ?>
        <?php if ($blad): ?>
            <p class="blad" role="alert"><?= e($blad) ?></p>
        <?php else: ?>
            <p class="akcje">
                <a href="#nadchodzace">Nadchodzące (<?= $sekcje['nadchodzace']['liczba'] ?>)</a>
                <a href="#historia">Historia (<?= $sekcje['historia']['liczba'] ?>)</a>
            </p>
            <?php foreach ($sekcje as $klucz => $sekcja): ?>
                <section class="karta" id="<?= e($klucz) ?>">
                    <h2><?= e($sekcja['tytul']) ?></h2>
                    <p class="pomoc"><?= e($sekcja['opis']) ?></p>
                    <?php if (!$sekcja['wizyty']): ?>
                        <p><?= e($sekcja['pusto']) ?></p>
                    <?php else: ?>
                        <div class="tabela" tabindex="0" role="region" aria-label="<?= e($sekcja['tytul']) ?> — na małym ekranie przewiń poziomo">
                            <table>
                                <thead>
                                    <tr><th scope="col">Numer</th><th scope="col">Usługa i salon</th>
                                        <th scope="col">Pracownik</th><th scope="col">Początek</th>
                                        <th scope="col">Koniec</th><th scope="col">Zapisana cena</th>
                                        <th scope="col">Status</th><th scope="col">Działania</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sekcja['wizyty'] as $w): ?>
                                        <tr>
                                            <td>#<?= (int) $w['nr_rezerwacji'] ?></td>
                                            <td><strong><?= e($w['usluga'] ?? 'Usługa niedostępna') ?></strong>
                                                <small><?= e(($w['salon'] ?? 'Salon niedostępny') . (!empty($w['miasto']) ? ' — ' . $w['miasto'] : '')) ?></small></td>
                                            <td><?= e(trim(($w['imie'] ?? '') . ' ' . ($w['nazwisko'] ?? '')) ?: 'Pracownik niedostępny') ?></td>
                                            <td><?= e(listaData($w['data_rozpoczecia'])) ?></td>
                                            <td><?= e(listaData($w['data_zakonczenia'])) ?></td>
                                            <td><?= e(number_format((float) $w['cena'], 2, ',', ' ')) ?> zł</td>
                                            <td><span class="status <?= e(isset($statusy[$w['status']]) ? $w['status'] : '') ?>"><?= e($statusy[$w['status']] ?? 'Nieznany status') ?></span>
                                                <?php if ($klucz === 'nadchodzace' && $w['data_rozpoczecia'] <= $teraz): ?><small>Wizyta trwa</small><?php endif; ?>
                                            </td>
                                            <td>
                                                <details>
                                                    <summary>Uwagi do wizyty</summary>
                                                    <p><?= nl2br(e($w['uwagi'] ?: 'Nie dodano uwag.')) ?></p>
                                                </details>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($sekcja['strony'] > 1): ?>
                            <nav class="stronicowanie" aria-label="Strony: <?= e($sekcja['tytul']) ?>">
                                <?php $parametry = ['nadchodzace' => $sekcje['nadchodzace']['strona'], 'historia' => $sekcje['historia']['strona']]; ?>
                                <?php if ($sekcja['strona'] > 1): ?>
                                    <?php $parametry[$klucz] = $sekcja['strona'] - 1; ?>
                                    <a href="?<?= e(http_build_query($parametry)) ?>#<?= e($klucz) ?>">← Poprzednia</a>
                                <?php endif; ?>
                                <span>Strona <?= $sekcja['strona'] ?> z <?= $sekcja['strony'] ?></span>
                                <?php if ($sekcja['strona'] < $sekcja['strony']): ?>
                                    <?php $parametry[$klucz] = $sekcja['strona'] + 1; ?>
                                    <a href="?<?= e(http_build_query($parametry)) ?>#<?= e($klucz) ?>">Następna →</a>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
            <p class="pomoc">Cena i godziny są zapisane przy rezerwacji. W sprawie zmiany terminu lub anulowania skontaktuj się z salonem.</p>
        <?php endif; ?>
    </main>
</body>
</html>
