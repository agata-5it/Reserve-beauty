<?php
require_once __DIR__ . '/../includes/auth.php';
$uzytkownik = wymagajRoli('klient');
header('Cache-Control: no-store');
$teraz = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));
$dzis = $teraz->format('Y-m-d');
$liczniki = null;
$wizyty = [];
$najblizsza = null;
$blad = '';
$komunikat = $_SESSION['wybor_sukces'] ?? '';
unset($_SESSION['wybor_sukces']);
$numerStrony = isset($_GET['strona']) && is_string($_GET['strona'])
    ? (int) filter_var($_GET['strona'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]) : 1;
$numerStrony = max(1, $numerStrony);
$liczbaStron = 1;
$statusy = ['oczekujaca' => 'Oczekująca', 'potwierdzona' => 'Potwierdzona',
    'zrealizowana' => 'Zrealizowana', 'anulowana' => 'Anulowana'];
function klientData(string $data, string $format = 'd.m.Y H:i'): string
{
    return (new DateTimeImmutable($data, new DateTimeZone('Europe/Warsaw')))->format($format);
}
try {
    // Każdy odczyt ograniczamy do konta z sesji, nigdy do ID z adresu.
    $q = $pdo->prepare("SELECT COUNT(*) AS wszystkie,
        COALESCE(SUM(status IN ('oczekujaca', 'potwierdzona') AND data_zakonczenia > ?), 0) AS nadchodzace,
        COALESCE(SUM(status <> 'anulowana' AND data_rozpoczecia >= ? AND data_rozpoczecia < ?), 0) AS dzisiejsze,
        COALESCE(SUM(status = 'zrealizowana'), 0) AS zrealizowane
        FROM rezerwacje WHERE id_uzytkownika = ?");
    $q->execute([$teraz->format('Y-m-d H:i:s'), $dzis . ' 00:00:00',
        $teraz->modify('+1 day')->format('Y-m-d') . ' 00:00:00', $uzytkownik['id_uzytkownika']]);
    $liczniki = $q->fetch(PDO::FETCH_ASSOC);
    $liczbaStron = max(1, (int) ceil($liczniki['wszystkie'] / 10));
    $numerStrony = min($numerStrony, $liczbaStron);
    $offset = ($numerStrony - 1) * 10;
    $sql = 'SELECT r.nr_rezerwacji, r.data_rozpoczecia, r.data_zakonczenia, r.cena, r.status,
        us.nazwa AS usluga, s.nazwa AS salon, s.miasto, u.imie, u.nazwisko
        FROM rezerwacje r
        JOIN uslugi us ON us.id_uslugi = r.id_uslugi
        JOIN salony s ON s.id_salonu = us.id_salonu
        JOIN pracownicy p ON p.id_pracownika = r.id_pracownika
        JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika
        WHERE r.id_uzytkownika = ?';
    $q = $pdo->prepare($sql . " AND r.status IN ('oczekujaca', 'potwierdzona')
        AND r.data_zakonczenia > ? ORDER BY r.data_rozpoczecia, r.nr_rezerwacji LIMIT 1");
    $q->execute([$uzytkownik['id_uzytkownika'], $teraz->format('Y-m-d H:i:s')]);
    $najblizsza = $q->fetch(PDO::FETCH_ASSOC);
    // Offset jest wyliczoną liczbą całkowitą, nie tekstem z przeglądarki.
    $q = $pdo->prepare($sql . ' ORDER BY r.data_rozpoczecia DESC, r.nr_rezerwacji DESC LIMIT 10 OFFSET ' . $offset);
    $q->execute([$uzytkownik['id_uzytkownika']]);
    $wizyty = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $wyjatek) {
    error_log('Panel klienta: SQLSTATE ' . $wyjatek->getCode());
    $blad = 'Nie udało się pobrać rezerwacji. Odśwież stronę, aby spróbować ponownie.';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel klienta — Reserve Beauty</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/klient.css?v=2">
</head>
<body class="strona-klienta panel-klienta">
    <nav class="menu-klienta" aria-label="Menu Klienta">
            <ul>
                <li>
                    <a href="<?= e(BASE_URL) ?>/klient/index.php" aria-current="page">Podsumowanie</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/klient/rezerwacje.php">Moje rezerwacje</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/oferta.php">Oferta usług</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a>
                </li>
            </ul>
        </nav>
    <main id="panel">
        <header class="pole">
            <div>
                <p class="marka">RESERVE BEAUTY</p>
                <h1>Panel klienta</h1>
                <p>Witaj, <?= e($uzytkownik['imie']) ?>! Tutaj znajdziesz swoje wizyty.</p>
            </div>
            <form
                action="<?= e(BASE_URL) ?>/wylogowanie.php"
                method="post"
                class="wylogowanie"
                >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['csrf_token']) ?>"
                >

                <button type="submit">Wyloguj się</button>
            </form>
        </header>
        <?php if ($komunikat): ?><p class="sukces" role="status"><?= e($komunikat) ?></p><?php endif; ?>
        <?php if ($blad): ?><p class="blad" role="alert"><?= e($blad) ?></p><?php else: ?>
            <h2>Twoje podsumowanie</h2>
            <dl class="kafelki">
                <?php foreach (['wszystkie' => 'Wszystkie rezerwacje', 'nadchodzace' => 'Nadchodzące i trwające wizyty', 'dzisiejsze' => 'Dzisiejsze wizyty bez anulowanych', 'zrealizowane' => 'Zrealizowane wizyty'] as $klucz => $etykieta): ?>
                    <div class="kafelek"><dt><?= e($etykieta) ?></dt><dd><?= (int) $liczniki[$klucz] ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <section class="karta">
                <h2>Najbliższa wizyta</h2>
                <?php if (!$najblizsza): ?>
                    <p>Nie masz nadchodzących wizyt. Wybierz usługę z naszej oferty.</p>
                    <a href="<?= e(BASE_URL) ?>/oferta.php">Przejdź do oferty →</a>
                <?php else: ?>
                    <p class="termin-wizyty"><?= e(klientData($najblizsza['data_rozpoczecia'])) ?> – <?= e(klientData($najblizsza['data_zakonczenia'], 'H:i')) ?></p>
                    <h3><?= e($najblizsza['usluga']) ?></h3>
                    <p><?= e($najblizsza['salon'] . ' — ' . $najblizsza['miasto']) ?><br>
                        Pracownik: <?= e($najblizsza['imie'] . ' ' . $najblizsza['nazwisko']) ?></p>
                    <span class="status <?= e($najblizsza['status']) ?>"><?= e($statusy[$najblizsza['status']] ?? $najblizsza['status']) ?></span>
                    <strong><?= e(number_format((float) $najblizsza['cena'], 2, ',', ' ')) ?> zł</strong>
                <?php endif; ?>
            </section>
            <section class="karta" id="wizyty">
                <h2>Moje rezerwacje</h2>
                <p class="pomoc">Historia Twoich wizyt. Cena oraz godziny pochodzą z zapisanej rezerwacji.</p>
                <?php if (!$wizyty): ?><p>Nie masz jeszcze żadnych rezerwacji.</p><?php else: ?>
                    <div class="tabela" tabindex="0" role="region" aria-label="Lista rezerwacji — na małym ekranie przewiń poziomo">
                        <table>
                            <thead><tr><th>Numer</th><th>Usługa i salon</th><th>Pracownik</th><th>Od</th><th>Do</th><th>Cena</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($wizyty as $w): ?>
                                    <tr>
                                        <td>#<?= (int) $w['nr_rezerwacji'] ?></td>
                                        <td><strong><?= e($w['usluga']) ?></strong><small><?= e($w['salon'] . ' — ' . $w['miasto']) ?></small></td>
                                        <td><?= e($w['imie'] . ' ' . $w['nazwisko']) ?></td>
                                        <td><?= e(klientData($w['data_rozpoczecia'])) ?></td>
                                        <td><?= e(klientData($w['data_zakonczenia'])) ?></td>
                                        <td><?= e(number_format((float) $w['cena'], 2, ',', ' ')) ?> zł</td>
                                        <td><span class="status <?= e($w['status']) ?>"><?= e($statusy[$w['status']] ?? $w['status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="stronicowanie">
                        <?php if ($numerStrony > 1): ?><a href="?strona=<?= $numerStrony - 1 ?>#wizyty">← Poprzednia</a><?php endif; ?>
                        <span>Strona <?= $numerStrony ?> z <?= $liczbaStron ?></span>
                        <?php if ($numerStrony < $liczbaStron): ?><a href="?strona=<?= $numerStrony + 1 ?>#wizyty">Następna →</a><?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
