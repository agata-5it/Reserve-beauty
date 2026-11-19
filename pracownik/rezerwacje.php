<?php
require_once __DIR__ . '/../includes/auth.php';
$konto = wymagajRoli('pracownik');
require_once __DIR__ . '/../includes/rezerwacje.php';
header('Cache-Control: no-store');
$strefa = new DateTimeZone('Europe/Warsaw');
$teraz = new DateTimeImmutable('now', $strefa);
$dzis = $teraz->setTime(0, 0);
$jutro = $dzis->modify('+1 day');
$statusy = ['oczekujaca' => 'Oczekująca', 'potwierdzona' => 'Potwierdzona',
    'zrealizowana' => 'Zrealizowana', 'anulowana' => 'Anulowana'];
$profil = null;
$blad = '';
$statusSukces = $_SESSION['pracownik_status_sukces'] ?? '';
$statusBlad = $_SESSION['pracownik_status_blad'] ?? '';
unset($_SESSION['pracownik_status_sukces'], $_SESSION['pracownik_status_blad']);
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
$tytul = 'Moje wizyty';
$widoki = ['dzis' => 'Dzisiejsze wizyty', 'nadchodzace' => 'Nadchodzące wizyty', 'historia' => 'Historia'];
$widok = 'nadchodzace';
$od = '';
$do = '';
$status = '';
$strona = 1;
$strony = 1;
$liczba = 0;

function filtrTekst(string $pole, string $domyslny = ''): string
{
    if (!isset($_GET[$pole])) { return $domyslny; }
    if (!is_string($_GET[$pole])) {
        throw new RuntimeException('Nieprawidłowy format filtrów. Wyczyść filtry i spróbuj ponownie.');
    }
    return trim($_GET[$pole]);
}

function filtrData(string $tekst): ?DateTimeImmutable
{
    if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $tekst)
        || $tekst < '1000-01-01' || $tekst > '9999-12-30') { return null; }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $tekst, new DateTimeZone('Europe/Warsaw'));
    return $d && $d->format('Y-m-d') === $tekst ? $d : null;
}

try {
    $widok = filtrTekst('widok', 'nadchodzace');
    $od = filtrTekst('od');
    $do = filtrTekst('do');
    $status = filtrTekst('status');
    if (!isset($widoki[$widok]) || ($status !== '' && !isset($statusy[$status]))) {
        throw new RuntimeException('Wybierz poprawny widok i status.');
    }
    $dataOd = $od !== '' ? filtrData($od) : null;
    $dataDo = $do !== '' ? filtrData($do) : null;
    if (($od !== '' && !$dataOd) || ($do !== '' && !$dataDo)) {
        throw new RuntimeException('Podaj prawidłowe daty.');
    }
    if ($dataOd && $dataDo && $dataOd > $dataDo) {
        throw new RuntimeException('Data końcowa nie może być wcześniejsza niż początkowa.');
    }
    $strona = (int) (filter_var(filtrTekst('strona', '1'), FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 1000000]]) ?: 1);
    if ($profil) {
        $parametry = [(int) $profil['id_pracownika']];
        if ($widok === 'dzis') {
            $warunek = ' AND r.data_rozpoczecia >= ? AND r.data_rozpoczecia < ?';
            $parametry[] = $dzis->format('Y-m-d H:i:s');
            $parametry[] = $jutro->format('Y-m-d H:i:s');
        } elseif ($widok === 'nadchodzace') {
            $warunek = " AND r.status IN ('oczekujaca', 'potwierdzona') AND r.data_zakonczenia > ?";
            $parametry[] = $teraz->format('Y-m-d H:i:s');
        } else {
            $warunek = " AND (r.status NOT IN ('oczekujaca', 'potwierdzona') OR r.data_zakonczenia <= ?)";
            $parametry[] = $teraz->format('Y-m-d H:i:s');
        }
        if ($dataOd) { $warunek .= ' AND r.data_rozpoczecia >= ?'; $parametry[] = $dataOd->format('Y-m-d H:i:s'); }
        if ($dataDo) { $warunek .= ' AND r.data_rozpoczecia < ?'; $parametry[] = $dataDo->modify('+1 day')->format('Y-m-d H:i:s'); }
        if ($status !== '') { $warunek .= ' AND r.status = ?'; $parametry[] = $status; }
        $q = $pdo->prepare('SELECT COUNT(*) FROM rezerwacje r WHERE r.id_pracownika = ?' . $warunek);
        $q->execute($parametry);
        $liczba = (int) $q->fetchColumn();
        $strony = max(1, (int) ceil($liczba / 15));
        $strona = min($strona, $strony);
        $offset = ($strona - 1) * 15;
        $kierunek = $widok === 'historia' ? 'DESC' : 'ASC';
        $q = $pdo->prepare($sqlWizyt . $warunek . ' ORDER BY r.data_rozpoczecia ' . $kierunek .
            ', r.nr_rezerwacji ' . $kierunek . ' LIMIT 15 OFFSET ' . $offset);
        $q->execute($parametry);
        $wizyty = $q->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $wyjatek) {
    error_log('Wizyty pracownika: SQLSTATE ' . $wyjatek->getCode());
    http_response_code(503);
    $blad = 'Nie udało się pobrać wizyt. Odśwież stronę, aby spróbować ponownie.';
} catch (RuntimeException $wyjatek) {
    http_response_code(400);
    $bladFiltrow = $wyjatek->getMessage();
}
$tytulListy = $widoki[$widok] ?? 'Moje wizyty';
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
            <li><a href="<?= e(BASE_URL) ?>/pracownik/index.php" >Podsumowanie</a></li>
            <li><a href="<?= e(BASE_URL) ?>/pracownik/rezerwacje.php" aria-current="page">Moje wizyty</a></li>
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
        <?php if ($statusSukces): ?><p class="sukces" role="status"><?= e($statusSukces) ?></p><?php endif; ?>
        <?php if ($statusBlad): ?><p class="blad" role="alert"><?= e($statusBlad) ?></p><?php endif; ?>
        <?php if ($blad): ?>
            <p class="blad" role="alert"><?= e($blad) ?></p>
        <?php elseif (!$profil): ?>
            <section class="karta">
                <h2>Brak profilu pracownika</h2>
                <p>Twoje konto nie zostało jeszcze przypisane do profilu pracownika i salonu. Poproś administratora o przypisanie w zakładce „Pracownicy”.</p>
            </section>
        <?php else: ?>
            <?php if ((int) $profil['aktywnosc'] !== 1): ?>
                <p class="pomoc">Twój profil pracownika jest nieaktywny. Możesz przeglądać dotychczasowe rezerwacje. Zmiany statusów może wykonać administrator.</p>
            <?php endif; ?>
            <section class="karta">
                <h2>Znajdź wizyty</h2>
                <form method="get" action="<?= e(BASE_URL) ?>/pracownik/rezerwacje.php">
                    <label>Widok
                        <select name="widok">
                            <?php foreach ($widoki as $wartosc => $nazwa): ?>
                                <option value="<?= e($wartosc) ?>" <?= $widok === $wartosc ? 'selected' : '' ?>><?= e($nazwa) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="formularz-filtrow">
                        <label>Data od<input type="date" name="od" min="1000-01-01" max="9999-12-30" value="<?= e($od) ?>"></label>
                        <label>Data do<input type="date" name="do" min="1000-01-01" max="9999-12-30" value="<?= e($do) ?>"></label>
                        <label>Status
                            <select name="status">
                                <option value="">Wszystkie statusy</option>
                                <?php foreach ($statusy as $wartosc => $nazwa): ?>
                                    <option value="<?= e($wartosc) ?>" <?= $status === $wartosc ? 'selected' : '' ?>><?= e($nazwa) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <p class="pomoc">Daty dotyczą początku wizyty i obejmują oba wskazane dni. Filtry działają w wybranym widoku. Anulowane wizyty znajdziesz w historii lub w widoku dzisiejszym.</p>
                    <div class="akcje"><button type="submit">Pokaż wizyty</button><a href="<?= e(BASE_URL) ?>/pracownik/rezerwacje.php">Wyczyść filtry</a></div>
                </form>
            </section>
            <?php if ($bladFiltrow): ?><p class="blad" role="alert"><?= e($bladFiltrow) ?></p><?php endif; ?>
            <?php if (!$bladFiltrow): ?>
                <section class="karta">
                    <h2><?= e($tytulListy) ?></h2>
                    <p class="pomoc">Znaleziono: <?= $liczba ?>. Nadchodzące obejmują także trwające wizyty. Historia zawiera również anulowane przyszłe wizyty. Upływ czasu nie zmienia statusu.</p>
                    <?php if (!$wizyty): ?>
                        <p>Brak wizyt do wyświetlenia.</p>
                    <?php else: ?>
                        <div class="tabela" tabindex="0" role="region" aria-label="Lista wizyt — na małym ekranie przewiń poziomo">
                            <table>
                                <thead><tr><th scope="col">Numer</th><th scope="col">Klient</th><th scope="col">Usługa</th><th scope="col">Początek</th><th scope="col">Koniec</th><th scope="col">Status</th><th scope="col">Działania</th></tr></thead>
                                <tbody>
                                    <?php foreach ($wizyty as $w): ?>
                                        <tr>
                                            <td>#<?= (int) $w['nr_rezerwacji'] ?></td>
                                            <td><?= e(trim(($w['imie'] ?? '') . ' ' . ($w['nazwisko'] ?? '')) ?: 'Konto klienta niedostępne') ?></td>
                                            <td><?= e($w['usluga'] ?? 'Usługa niedostępna') ?></td>
                                            <td><?= e(pracownikData($w['data_rozpoczecia'])) ?></td>
                                            <td><?= e(pracownikData($w['data_zakonczenia'])) ?></td>
                                            <td><span class="status <?= e(isset($statusy[$w['status']]) ? $w['status'] : '') ?>"><?= e($statusy[$w['status']] ?? 'Nieznany status') ?></span></td>
                                            <td>
                                                <?php $akcje = (int) $profil['aktywnosc'] === 1 ? rezerwacjaAkcjeStatusu($w['status'], $w['data_zakonczenia'], $teraz) : []; ?>
                                                <?php if ($akcje): ?>
                                                    <form class="akcje" method="post" action="<?= e(BASE_URL) ?>/pracownik/zmien-status.php">
                                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="nr_rezerwacji" value="<?= (int) $w['nr_rezerwacji'] ?>">
                                                        <input type="hidden" name="poprzedni" value="<?= e($w['status']) ?>">
                                                        <?php foreach ($akcje as $kod => $etykieta): ?>
                                                            <button type="submit" name="nowy" value="<?= e($kod) ?>" <?php if ($kod === 'anulowana'): ?>onclick="return confirm('Anulować tę wizytę? Nie będzie można jej zwyczajnie przywrócić.');"<?php endif; ?>><?= e($etykieta) ?></button>
                                                        <?php endforeach; ?>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="pomoc">Brak dostępnych zmian</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <?php if ($strony > 1): ?>
                        <nav class="stronicowanie" aria-label="Strony wizyt">
                            <?php $link = ['widok' => $widok, 'od' => $od, 'do' => $do, 'status' => $status]; ?>
                            <?php if ($strona > 1): ?>
                                <?php $link['strona'] = $strona - 1; ?>
                                <a href="?<?= e(http_build_query($link)) ?>">← Poprzednia</a>
                            <?php endif; ?>
                            <span>Strona <?= $strona ?> z <?= $strony ?></span>
                            <?php if ($strona < $strony): ?>
                                <?php $link['strona'] = $strona + 1; ?>
                                <a href="?<?= e(http_build_query($link)) ?>">Następna →</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
