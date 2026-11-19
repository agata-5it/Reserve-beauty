<?php
require_once __DIR__ . '/../includes/auth.php';
$konto = wymagajRoli('pracownik');
header('Cache-Control: no-store');
$strefa = new DateTimeZone('Europe/Warsaw');
$teraz = new DateTimeImmutable('now', $strefa);
$dzis = $teraz->setTime(0, 0);
$jutro = $dzis->modify('+1 day');
$statusy = ['oczekujaca' => 'Oczekująca', 'potwierdzona' => 'Potwierdzona',
    'zrealizowana' => 'Zrealizowana', 'anulowana' => 'Anulowana'];
$profil = null;
$blad = '';
$bladFiltrow = '';
$wizyty = [];
$liczniki = [];

function pracownikData(string $tekst): string
{
    return (new DateTimeImmutable($tekst, new DateTimeZone('Europe/Warsaw')))->format('d.m.Y H:i');
}

try {
    // Właściciel panelu zawsze wynika z konta. Ignorujemy id_pracownika z URL.
    $q = $pdo->prepare('SELECT p.id_pracownika, p.aktywnosc, s.nazwa AS salon, s.miasto
        FROM pracownicy p LEFT JOIN salony s ON s.id_salonu = p.id_salonu
        WHERE p.id_uzytkownika = ?');
    $q->execute([(int) $konto['id_uzytkownika']]);
    $profil = $q->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $wyjatek) {
    error_log('Panel pracownika: profil, SQLSTATE ' . $wyjatek->getCode());
    http_response_code(503);
    $blad = 'Nie udało się pobrać profilu pracownika. Spróbuj ponownie za chwilę.';
}

// Cena i godziny są zapisane przy wizycie. Nie filtrujemy aktywności usług.
$sqlWizyt = 'SELECT r.nr_rezerwacji, r.data_rozpoczecia, r.data_zakonczenia, r.status,
        u.imie, u.nazwisko, us.nazwa AS usluga
    FROM rezerwacje r
    LEFT JOIN uzytkownicy u ON u.id_uzytkownika = r.id_uzytkownika
    LEFT JOIN uslugi us ON us.id_uslugi = r.id_uslugi
    WHERE r.id_pracownika = ?';
$tytul = 'Panel pracownika';
$tytulListy = 'Dzisiejsze wizyty';
if ($profil) {
    try {
        $q = $pdo->prepare("SELECT COUNT(*) AS wszystkie,
            COALESCE(SUM(data_rozpoczecia >= ? AND data_rozpoczecia < ?), 0) AS dzisiejsze,
            COALESCE(SUM(status IN ('oczekujaca', 'potwierdzona') AND data_zakonczenia > ?), 0) AS nadchodzace,
            COALESCE(SUM(status NOT IN ('oczekujaca', 'potwierdzona') OR data_zakonczenia <= ?), 0) AS historia
            FROM rezerwacje WHERE id_pracownika = ?");
        $q->execute([$dzis->format('Y-m-d H:i:s'), $jutro->format('Y-m-d H:i:s'),
            $teraz->format('Y-m-d H:i:s'), $teraz->format('Y-m-d H:i:s'), (int) $profil['id_pracownika']]);
        $liczniki = $q->fetch(PDO::FETCH_ASSOC);
        $q = $pdo->prepare($sqlWizyt . ' AND r.data_rozpoczecia >= ? AND r.data_rozpoczecia < ?
            ORDER BY r.data_rozpoczecia, r.nr_rezerwacji LIMIT 10');
        $q->execute([(int) $profil['id_pracownika'], $dzis->format('Y-m-d H:i:s'), $jutro->format('Y-m-d H:i:s')]);
        $wizyty = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $wyjatek) {
        error_log('Panel pracownika: wizyty, SQLSTATE ' . $wyjatek->getCode());
        http_response_code(503);
        $blad = 'Nie udało się pobrać wizyt. Odśwież stronę, aby spróbować ponownie.';
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($tytul) ?> — Reserve Beauty</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/klient.css?v=2">
</head>
<body class="strona-klienta panel-klienta">
    <nav class="menu-klienta" aria-label="Menu pracownika">
        <ul>
            <li><a href="<?= e(BASE_URL) ?>/pracownik/index.php" aria-current="page">Podsumowanie</a></li>
            <li><a href="<?= e(BASE_URL) ?>/pracownik/rezerwacje.php" >Moje wizyty</a></li>
            <li><a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a></li>
        </ul>
    </nav>
    <main>
        <header class="pole">
            <div>
                <p class="marka">RESERVE BEAUTY</p>
                <h1><?= e($tytul) ?></h1>
                <p><?= e($konto['imie'] . ' ' . $konto['nazwisko']) ?></p>
                <?php if ($profil): ?><p><?= e(($profil['salon'] ?? 'Salon niedostępny') . (!empty($profil['miasto']) ? ' — ' . $profil['miasto'] : '')) ?></p><?php endif; ?>
            </div>
            <form method="post" action="<?= e(BASE_URL) ?>/wylogowanie.php">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <button type="submit">Wyloguj się</button>
            </form>
        </header>
        <?php if ($blad): ?>
            <p class="blad" role="alert"><?= e($blad) ?></p>
        <?php elseif (!$profil): ?>
            <section class="karta">
                <h2>Brak profilu pracownika</h2>
                <p>Twoje konto nie zostało jeszcze przypisane do profilu pracownika i salonu. Poproś administratora o przypisanie w zakładce „Pracownicy”.</p>
            </section>
        <?php else: ?>
            <?php if ((int) $profil['aktywnosc'] !== 1): ?>
                <p class="pomoc">Twój profil pracownika jest nieaktywny. Nowe wizyty są zablokowane, ale nadal możesz przeglądać dotychczasowe rezerwacje.</p>
            <?php endif; ?>
            <dl class="kafelki">
                <?php foreach (['dzisiejsze' => 'Dzisiejsze wizyty, także anulowane', 'nadchodzace' => 'Nadchodzące i trwające', 'historia' => 'Historia, w tym anulowane', 'wszystkie' => 'Wszystkie rezerwacje'] as $pole => $etykieta): ?>
                    <div class="kafelek"><dt><?= e($etykieta) ?></dt><dd><?= (int) $liczniki[$pole] ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <p class="akcje">
                <a href="<?= e(BASE_URL) ?>/pracownik/rezerwacje.php?widok=dzis">Dzisiejsze wizyty</a>
                <a href="<?= e(BASE_URL) ?>/pracownik/rezerwacje.php?widok=nadchodzace">Nadchodzące wizyty</a>
                <a href="<?= e(BASE_URL) ?>/pracownik/rezerwacje.php?widok=historia">Historia</a>
            </p>
            <?php if (!$bladFiltrow): ?>
                <section class="karta">
                    <h2><?= e($tytulListy) ?></h2>
                    <p class="pomoc">Pierwsze 10 wizyt rozpoczynających się dzisiaj, w tym anulowane.</p>
                    <?php if (!$wizyty): ?>
                        <p>Brak wizyt do wyświetlenia.</p>
                    <?php else: ?>
                        <div class="tabela" tabindex="0" role="region" aria-label="Lista wizyt — na małym ekranie przewiń poziomo">
                            <table>
                                <thead><tr><th scope="col">Numer</th><th scope="col">Klient</th><th scope="col">Usługa</th><th scope="col">Początek</th><th scope="col">Koniec</th><th scope="col">Status</th></tr></thead>
                                <tbody>
                                    <?php foreach ($wizyty as $w): ?>
                                        <tr>
                                            <td>#<?= (int) $w['nr_rezerwacji'] ?></td>
                                            <td><?= e(trim(($w['imie'] ?? '') . ' ' . ($w['nazwisko'] ?? '')) ?: 'Konto klienta niedostępne') ?></td>
                                            <td><?= e($w['usluga'] ?? 'Usługa niedostępna') ?></td>
                                            <td><?= e(pracownikData($w['data_rozpoczecia'])) ?></td>
                                            <td><?= e(pracownikData($w['data_zakonczenia'])) ?></td>
                                            <td><span class="status <?= e(isset($statusy[$w['status']]) ? $w['status'] : '') ?>"><?= e($statusy[$w['status']] ?? 'Nieznany status') ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <p><a href="<?= e(BASE_URL) ?>/pracownik/rezerwacje.php?widok=dzis">Pokaż wszystkie dzisiejsze wizyty →</a></p>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
