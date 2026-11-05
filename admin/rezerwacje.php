<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = wymagajRoli('admin');

function rezPole(array $dane, string $klucz): string
{
    return is_string($dane[$klucz] ?? '') ? trim($dane[$klucz] ?? '') : '!';
}

function rezData(string $data): bool
{
    return preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $data, $czesci)
        && (int) $czesci[1] >= 1000
        && checkdate((int) $czesci[2], (int) $czesci[3], (int) $czesci[1]);
}

// Te same reguły decydują o przyciskach w tabeli i o zapisie w bazie.
function rezDzialania(string $status, string $koniec, string $teraz): array
{
    if ($status === 'oczekujaca') {
        return ['potwierdzona' => 'Potwierdź', 'anulowana' => 'Anuluj'];
    }
    if ($status === 'potwierdzona') {
        $akcje = [];
        if ($koniec <= $teraz) {
            $akcje['zrealizowana'] = 'Oznacz jako zrealizowaną';
        }
        $akcje['anulowana'] = 'Anuluj';
        return $akcje;
    }
    return [];
}

$statusy = ['oczekujaca' => 'Oczekująca', 'potwierdzona' => 'Potwierdzona',
    'zrealizowana' => 'Zrealizowana', 'anulowana' => 'Anulowana'];
$menu = ['index.php' => 'Podsumowanie', 'kategorie.php' => 'Kategorie', 'salony.php' => 'Salony',
    'uslugi.php' => 'Usługi', 'pracownicy.php' => 'Pracownicy', 'dostepnosc.php' => 'Dostępność',
    'urlopy.php' => 'Urlopy', 'rezerwacje.php' => 'Rezerwacje'];
$strefa = new DateTimeZone('Europe/Warsaw');
$filtry = [];
foreach (['od', 'do', 'salon', 'pracownik', 'status'] as $pole) {
    $filtry[$pole] = rezPole($_GET, $pole);
}
$sciezka = '/admin/rezerwacje.php';
$parametry = http_build_query(array_filter($filtry, 'strlen'));
$powrot = $sciezka . ($parametry !== '' ? '?' . $parametry : '');
$blad = '';
$sukces = $_SESSION['rez_sukces'] ?? '';
unset($_SESSION['rez_sukces']);

// 1. Zmiana statusu. Blokada wiersza chroni przed zapisem z nieaktualnej strony.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!sprawdzCsrf()) {
            throw new RuntimeException('Formularz wygasł. Odśwież stronę i spróbuj ponownie.');
        }
        $nr = filter_var(rezPole($_POST, 'nr'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $nowy = rezPole($_POST, 'nowy');
        if (!$nr) {
            throw new RuntimeException('Nieprawidłowy numer rezerwacji.');
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT status, data_zakonczenia FROM rezerwacje WHERE nr_rezerwacji = ? FOR UPDATE');
        $stmt->execute([$nr]);
        $wizyta = $stmt->fetch();
        if (!$wizyta) {
            throw new RuntimeException('Nie znaleziono rezerwacji.');
        }
        if ($wizyta['status'] !== rezPole($_POST, 'poprzedni')) {
            throw new RuntimeException('Status zmienił się od otwarcia strony. Sprawdź aktualny wpis w tabeli.');
        }
        $teraz = (new DateTimeImmutable('now', $strefa))->format('Y-m-d H:i:s');
        $dozwolone = rezDzialania($wizyta['status'], $wizyta['data_zakonczenia'], $teraz);
        if (!isset($dozwolone[$nowy])) {
            throw new RuntimeException($wizyta['status'] === 'potwierdzona' && $nowy === 'zrealizowana'
                ? 'Wizytę można oznaczyć jako zrealizowaną dopiero po jej zakończeniu.'
                : 'Ta zmiana statusu jest niedozwolona.');
        }
        $stmt = $pdo->prepare('UPDATE rezerwacje SET status = ? WHERE nr_rezerwacji = ?');
        $stmt->execute([$nowy, $nr]);
        // Rezerwacja i historia muszą zapisać się razem. Nazwisko zachowujemy
        // również w historii, aby późniejsza edycja konta nie zmieniła wpisu.
        $stmt = $pdo->prepare('INSERT INTO historia_statusow_rezerwacji
            (nr_rezerwacji, id_administratora, administrator, status_przed, status_po, zmieniono)
            VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$nr, $admin['id_uzytkownika'], $admin['imie'] . ' ' . $admin['nazwisko'],
            $wizyta['status'], $nowy, $teraz]);
        $pdo->commit();
        $_SESSION['rez_sukces'] = 'Rezerwacja nr ' . $nr . ' — nowy status: ' . $statusy[$nowy] . '.';
        przekieruj($powrot);
        exit;
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $blad = 'Nie udało się zapisać zmiany. Odśwież listę i spróbuj ponownie.';
        if ((string) $ex->getCode() === '45000') {
            $opis = $ex->errorInfo[2] ?? '';
            $blad = strpos($opis, 'urlop') !== false
                ? 'Baza zablokowała zmianę: pracownik ma w tym terminie urlop.'
                : 'Baza zablokowała zmianę. Sprawdź godziny wizyty i inne rezerwacje pracownika.';
        }
        error_log('Rezerwacje: zmiana statusu, SQLSTATE ' . $ex->getCode());
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $blad = $ex->getMessage();
    }
}

// 2. Pobranie opcji filtrów i listy wizyt.
$salony = $pracownicy = $wiersze = [];
$bladListy = '';
try {
    $salony = $pdo->query('SELECT id_salonu AS id, CONCAT(nazwa, \' — \', miasto) AS nazwa FROM salony ORDER BY nazwa')->fetchAll();
    $pracownicy = $pdo->query("SELECT p.id_pracownika AS id, CONCAT(u.imie, ' ', u.nazwisko, ' — ', s.nazwa) AS nazwa
        FROM pracownicy p JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika
        JOIN salony s ON s.id_salonu = p.id_salonu ORDER BY u.nazwisko, u.imie")->fetchAll();

    $warunki = [];
    $wartosci = [];
    foreach (['od', 'do'] as $pole) {
        if ($filtry[$pole] !== '' && !rezData($filtry[$pole])) {
            throw new RuntimeException('Wprowadź poprawne daty w filtrze.');
        }
    }
    if ($filtry['od'] !== '' && $filtry['do'] !== '' && $filtry['od'] > $filtry['do']) {
        throw new RuntimeException('Data „do” nie może być wcześniejsza niż data „od”.');
    }
    if ($filtry['od'] !== '') {
        $warunki[] = 'r.data_rozpoczecia >= ?';
        $wartosci[] = $filtry['od'] . ' 00:00:00';
    }
    if ($filtry['do'] !== '') {
        $warunki[] = 'r.data_rozpoczecia <= ?';
        $wartosci[] = $filtry['do'] . ' 23:59:59';
    }
    foreach (['salon' => 's.id_salonu', 'pracownik' => 'p.id_pracownika'] as $pole => $kolumna) {
        if ($filtry[$pole] !== '') {
            if (!filter_var($filtry[$pole], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
                throw new RuntimeException('Wybierz salon i pracownika z listy lub wyczyść filtry.');
            }
            $warunki[] = $kolumna . ' = ?';
            $wartosci[] = $filtry[$pole];
        }
    }
    if ($filtry['status'] !== '') {
        if (!isset($statusy[$filtry['status']])) { throw new RuntimeException('Wybrano nieprawidłowy status.'); }
        $warunki[] = 'r.status = ?';
        $wartosci[] = $filtry['status'];
    }

    // Cena i obie godziny pochodzą z rezerwacji, nawet gdy usługa później się zmieni.
    $sql = "SELECT r.nr_rezerwacji, r.data_rozpoczecia, r.data_zakonczenia, r.cena, r.status,
            CONCAT(k.imie, ' ', k.nazwisko) AS klient, CONCAT(u.imie, ' ', u.nazwisko) AS pracownik,
            CONCAT(s.nazwa, ' — ', s.miasto) AS salon, us.nazwa AS usluga
        FROM rezerwacje r
        JOIN uzytkownicy k ON k.id_uzytkownika = r.id_uzytkownika
        JOIN pracownicy p ON p.id_pracownika = r.id_pracownika
        JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika
        JOIN uslugi us ON us.id_uslugi = r.id_uslugi
        JOIN salony s ON s.id_salonu = us.id_salonu";
    if ($warunki) { $sql .= ' WHERE ' . implode(' AND ', $warunki); }
    $stmt = $pdo->prepare($sql . ' ORDER BY r.data_rozpoczecia DESC, r.nr_rezerwacji DESC');
    $stmt->execute($wartosci);
    $wiersze = $stmt->fetchAll();
} catch (PDOException $ex) {
    $bladListy = 'Nie udało się pobrać listy rezerwacji.';
    error_log('Rezerwacje: lista, SQLSTATE ' . $ex->getCode());
} catch (RuntimeException $ex) {
    $bladListy = $ex->getMessage();
}
// CSV korzysta z tych samych wyników co tabela, również przy pustej liście.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && rezPole($_GET, 'eksport') === 'csv' && $bladListy === '') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rezerwacje-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $plik = fopen('php://output', 'wb');
    fwrite($plik, "\xEF\xBB\xBF"); // Polskie znaki w Excelu.
    fputcsv($plik, ['Nr rezerwacji', 'Klient', 'Salon', 'Pracownik', 'Usługa',
        'Początek', 'Koniec', 'Cena (zł)', 'Status'], ';', '"', '');
    foreach ($wiersze as $r) {
        $pola = [$r['nr_rezerwacji'], $r['klient'], $r['salon'], $r['pracownik'],
            $r['usluga'], $r['data_rozpoczecia'], $r['data_zakonczenia'],
            str_replace('.', ',', $r['cena']), $statusy[$r['status']]];
        foreach ($pola as &$pole) {
            // Tekst wpisany przez użytkownika nie powinien stać się formułą arkusza.
            if (preg_match('/^(?:[\t\r\n]|[\s\p{Z}]*[=+@-])/u', (string) $pole)) {
                $pole = "'" . $pole;
            }
        }
        unset($pole);
        fputcsv($plik, $pola, ';', '"', '');
    }
    fclose($plik);
    exit;
}

$nrHistorii = filter_var(rezPole($_GET, 'historia'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$historia = [];
$bladHistorii = '';
if ($nrHistorii) {
    try {
        $stmt = $pdo->prepare('SELECT administrator, zmieniono, status_przed, status_po
            FROM historia_statusow_rezerwacji WHERE nr_rezerwacji = ? ORDER BY id_zmiany DESC');
        $stmt->execute([$nrHistorii]);
        $historia = $stmt->fetchAll();
    } catch (PDOException $ex) {
        $bladHistorii = 'Nie można pobrać historii. Sprawdź, czy zaimportowano tabelę historii.';
        error_log('Historia: SQLSTATE ' . $ex->getCode());
    }
}
$kolejnyParametr = $parametry === '' ? '?' : '&';
$teraz = (new DateTimeImmutable('now', $strefa))->format('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rezerwacje — Reserve Beauty</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin2.css">
    <style>
        #rezerwacje .filtry { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr)); gap:14px; align-items:end; }
        #rezerwacje label { display:grid; gap:6px; font-weight:bold; }
        #rezerwacje select, #rezerwacje input[type=date] { box-sizing:border-box; width:100%; padding:10px; font:inherit; border:1px solid #d9c5ce; border-radius:8px; background:white; }
        #rezerwacje .sterowanie { grid-column:1/-1; display:flex; align-items:center; gap:16px; }
        #rezerwacje .sterowanie a { color:#78334e; }
        #rezerwacje td { vertical-align:top; }
        #rezerwacje small, #rezerwacje time { display:block; }
        #rezerwacje .dzialania { display:grid; gap:6px; margin-top:8px; justify-content:stretch; }
        #rezerwacje .dzialania button { font-size:14px; padding:7px 12px; }
        #rezerwacje .anuluj { color:#76334c; background:#f7edf1; }
    </style>
</head>
<body class="strona-konta">
<nav class="admin-menu" aria-label="Menu administratora">
    <ul class="admin-menu_lista">
        <?php foreach ($menu as $plik => $nazwa): ?>
            <li><a href="<?= e(BASE_URL . '/admin/' . $plik) ?>" <?= $plik === 'rezerwacje.php' ? 'aria-current="page"' : '' ?>><?= e($nazwa) ?></a></li>
        <?php endforeach; ?>
        <li><a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a></li>
    </ul>
</nav>
<main id="rezerwacje">
    <section class="karta">
        <h1>Rezerwacje</h1>
        <p>Wybierz filtry, aby znaleźć wizytę. Zakres dat dotyczy początku wizyty i obejmuje oba wybrane dni.</p>
        <?php if ($sukces): ?><p class="ok" role="status"><?= e($sukces) ?></p><?php endif; ?>
        <?php if ($blad): ?><p class="blad" role="alert"><?= e($blad) ?></p><?php endif; ?>
        <form class="filtry" method="get" action="<?= e(BASE_URL . $sciezka) ?>">
            <label>Data od<input type="date" name="od" value="<?= e($filtry['od']) ?>"></label>
            <label>Data do<input type="date" name="do" value="<?= e($filtry['do']) ?>"></label>
            <?php foreach (['salon' => ['Salon', $salony], 'pracownik' => ['Pracownik', $pracownicy]] as $pole => [$etykieta, $opcje]): ?>
                <label><?= e($etykieta) ?>
                    <select name="<?= e($pole) ?>">
                        <option value="">Wszystkie</option>
                        <?php foreach ($opcje as $opcja): ?>
                            <option value="<?= (int) $opcja['id'] ?>" <?= (string) $opcja['id'] === $filtry[$pole] ? 'selected' : '' ?>><?= e($opcja['nazwa']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endforeach; ?>
            <label>Status
                <select name="status">
                    <option value="">Wszystkie</option>
                    <?php foreach ($statusy as $kod => $nazwa): ?>
                        <option value="<?= e($kod) ?>" <?= $filtry['status'] === $kod ? 'selected' : '' ?>><?= e($nazwa) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="sterowanie"><button type="submit">Filtruj</button><a href="<?= e(BASE_URL . $sciezka) ?>">Wyczyść filtry</a></div>
        </form>
    </section>
    <section class="karta">
        <h2>Lista rezerwacji</h2>
        <?php if ($bladListy === ''): ?>
            <p><a class="link" href="<?= e(BASE_URL . $powrot . $kolejnyParametr . 'eksport=csv') ?>">Pobierz wyniki do CSV</a></p>
        <?php endif; ?>
        <?php if ($bladListy): ?>
            <p class="blad" role="alert"><?= e($bladListy) ?></p>
        <?php elseif (!$wiersze): ?>
            <p>Brak rezerwacji dla wybranych filtrów.</p>
        <?php else: ?>
            <div class="tabela">
                <table>
                    <thead><tr><th>Nr / klient</th><th>Salon / pracownik</th><th>Usługa / cena</th><th>Początek i koniec</th><th>Status i działania</th></tr></thead>
                    <tbody>
                    <?php foreach ($wiersze as $r): ?>
                        <tr>
                            <td><strong>#<?= (int) $r['nr_rezerwacji'] ?></strong><br><?= e($r['klient']) ?></td>
                            <td><?= e($r['salon']) ?><small><?= e($r['pracownik']) ?></small></td>
                            <td><?= e($r['usluga']) ?><small><?= number_format((float) $r['cena'], 2, ',', ' ') ?> zł</small></td>
                            <td>
                                <?php foreach (['Od' => 'data_rozpoczecia', 'Do' => 'data_zakonczenia'] as $opis => $pole): ?>
                                    <time datetime="<?= e(str_replace(' ', 'T', $r[$pole])) ?>"><?= e($opis . ': ' . (new DateTimeImmutable($r[$pole], $strefa))->format('d.m.Y H:i')) ?></time>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <strong><?= e($statusy[$r['status']] ?? $r['status']) ?></strong>
                                <small><a href="<?= e(BASE_URL . $powrot . $kolejnyParametr . 'historia=' . (int) $r['nr_rezerwacji'] . '#historia') ?>">Historia zmian</a></small>
                                <?php $akcje = rezDzialania($r['status'], $r['data_zakonczenia'], $teraz); ?>
                                <?php if ($akcje): ?>
                                    <form class="dzialania" method="post" action="<?= e(BASE_URL . $powrot) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="nr" value="<?= (int) $r['nr_rezerwacji'] ?>">
                                        <input type="hidden" name="poprzedni" value="<?= e($r['status']) ?>">
                                        <?php foreach ($akcje as $kod => $etykieta): ?>
                                            <button type="submit" name="nowy" value="<?= e($kod) ?>" <?php if ($kod === 'anulowana'): ?>class="anuluj" onclick="return confirm('Anulować tę rezerwację? Nie będzie można jej przywrócić z listy.');"<?php endif; ?>><?= e($etykieta) ?></button>
                                        <?php endforeach; ?>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php if ($nrHistorii): ?>
        <section class="karta" id="historia">
            <h2>Historia rezerwacji nr <?= (int) $nrHistorii ?></h2>
            <?php if ($bladHistorii): ?>
                <p class="blad" role="alert"><?= e($bladHistorii) ?></p>
            <?php elseif (!$historia): ?>
                <p>Brak zapisanych zmian statusu tej rezerwacji.</p>
            <?php else: ?>
                <div class="tabela">
                    <table>
                        <thead><tr><th>Kiedy (czas polski)</th><th>Kto</th><th>Poprzedni status</th><th>Nowy status</th></tr></thead>
                        <tbody>
                            <?php foreach ($historia as $wpis): ?>
                                <tr>
                                    <td><?= e($wpis['zmieniono']) ?></td>
                                    <td><?= e($wpis['administrator']) ?></td>
                                    <td><?= e($statusy[$wpis['status_przed']]) ?></td>
                                    <td><?= e($statusy[$wpis['status_po']]) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>