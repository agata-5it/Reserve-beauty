<?php
require_once __DIR__ . '/../includes/auth.php';
wymagajRoli('admin');

function urlopTekst(array $dane, string $pole): string
{
    return isset($dane[$pole]) && is_string($dane[$pole]) ? trim($dane[$pole]) : '';
}

function urlopId($wartosc): int
{
    if (!is_string($wartosc) && !is_int($wartosc)) {
        return 0;
    }
    return (int) (filter_var($wartosc, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647]
    ]) ?: 0);
}

function urlopData(string $tekst): ?DateTimeImmutable
{
    // Ograniczenie odpowiada zakresowi DATE w MySQL. Ostatni dzień zostawia
    // miejsce na obliczenie północy następnego dnia (koniec wyłączny).
    if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $tekst) ||
        $tekst < '1000-01-01' || $tekst > '9999-12-30') {
        return null;
    }
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $tekst, new DateTimeZone('Europe/Warsaw'));
    return $data && $data->format('Y-m-d') === $tekst ? $data : null;
}

function urlopPokazDate(string $data): string
{
    $wynik = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
    return $wynik ? $wynik->format('d.m.Y') : $data;
}

$blad = '';
$komunikat = $_SESSION['urlopy_komunikat'] ?? '';
unset($_SESSION['urlopy_komunikat']);
$formularz = ['id_urlopu' => 0, 'id_pracownika' => '', 'data_od' => '', 'data_do' => ''];
$czyPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($czyPost) {
    $akcja = urlopTekst($_POST, 'akcja');
    $idUrlopu = urlopId($_POST['id_urlopu'] ?? null);
    $idPracownika = urlopId($_POST['id_pracownika'] ?? null);
    if ($akcja === 'zapisz') {
        $formularz = ['id_urlopu' => $idUrlopu, 'id_pracownika' => (string) $idPracownika,
            'data_od' => urlopTekst($_POST, 'data_od'), 'data_do' => urlopTekst($_POST, 'data_do')];
    }
    try {
        if (!sprawdzCsrf()) {
            throw new RuntimeException('Formularz wygasł. Odśwież stronę i spróbuj ponownie.');
        }
        if (!in_array($akcja, ['zapisz', 'anuluj'], true) ||
            (!$idUrlopu && ($akcja === 'anuluj' || urlopTekst($_POST, 'id_urlopu') !== '0'))) {
            throw new RuntimeException('Nieprawidłowe działanie lub identyfikator urlopu.');
        }
        if (!$idPracownika) {
            throw new RuntimeException('Wybierz pracownika.');
        }
        if ($akcja === 'zapisz') {
            $od = urlopData($formularz['data_od']);
            $do = urlopData($formularz['data_do']);
            if (!$od || !$do) {
                throw new RuntimeException('Podaj poprawną datę początku i końca urlopu.');
            }
            if ($do < $od) {
                throw new RuntimeException('Data końcowa nie może być wcześniejsza niż początkowa.');
            }
        }

        // Wstępny odczyt wskazuje salon do zablokowania. Po blokadzie ponownie
        // odczytujemy pracownika, żeby wykryć ewentualną zmianę jego salonu.
        $zapytanie = $pdo->prepare('SELECT id_salonu FROM pracownicy WHERE id_pracownika = ?');
        $zapytanie->execute([$idPracownika]);
        $idSalonu = (int) $zapytanie->fetchColumn();
        if (!$idSalonu) {
            throw new RuntimeException('Wybrany pracownik nie istnieje.');
        }

        $pdo->beginTransaction();
        // Kolejność blokad: salon, pracownik, urlop. Wspólna blokada salonu
        // szereguje zapisy urlopów różnych pracowników tego samego salonu.
        $zapytanie = $pdo->prepare('SELECT id_salonu FROM salony WHERE id_salonu = ? FOR UPDATE');
        $zapytanie->execute([$idSalonu]);
        if (!$zapytanie->fetch()) {
            throw new RuntimeException('Salon już nie istnieje.');
        }
        $zapytanie = $pdo->prepare('SELECT id_salonu FROM pracownicy WHERE id_pracownika = ? FOR UPDATE');
        $zapytanie->execute([$idPracownika]);
        if ((int) $zapytanie->fetchColumn() !== $idSalonu) {
            throw new RuntimeException('Dane pracownika zmieniły się. Odśwież stronę i spróbuj ponownie.');
        }

        if ($idUrlopu) {
            $zapytanie = $pdo->prepare('SELECT * FROM urlopy WHERE id_urlopu = ? FOR UPDATE');
            $zapytanie->execute([$idUrlopu]);
            $urlop = $zapytanie->fetch();
            if (!$urlop || (int) $urlop['id_pracownika'] !== $idPracownika) {
                throw new RuntimeException('Nie znaleziono urlopu tego pracownika.');
            }
            if ($urlop['status'] !== 'zaplanowany') {
                throw new RuntimeException('Ten urlop został już anulowany. Aby zaplanować kolejny, dodaj nowy wpis.');
            }
            // Ochrona przed nadpisaniem zmian z drugiej otwartej karty.
            if (urlopTekst($_POST, 'poprzednia_data_od') !== $urlop['data_od'] ||
                urlopTekst($_POST, 'poprzednia_data_do') !== $urlop['data_do']) {
                throw new RuntimeException('Urlop został zmieniony w międzyczasie. Otwórz jego edycję ponownie.');
            }
        }

        if ($akcja === 'anuluj') {
            // Anulowanie zachowuje wpis i przestaje blokować terminy.
            $zapytanie = $pdo->prepare("UPDATE urlopy SET status = 'anulowany' WHERE id_urlopu = ?");
            $zapytanie->execute([$idUrlopu]);
        } else {
            // FOR UPDATE zapewnia aktualny odczyt po oczekiwaniu na blokadę salonu.
            $zapytanie = $pdo->prepare(
                "SELECT u.id_urlopu, u.id_pracownika FROM urlopy u
                 JOIN pracownicy p ON p.id_pracownika = u.id_pracownika
                 WHERE p.id_salonu = ? AND u.status = 'zaplanowany'
                   AND u.id_urlopu <> ? AND u.data_od <= ? AND u.data_do >= ?
                 ORDER BY u.id_urlopu FOR UPDATE"
            );
            $zapytanie->execute([$idSalonu, $idUrlopu, $formularz['data_do'], $formularz['data_od']]);
            $konflikty = $zapytanie->fetchAll();
            foreach ($konflikty as $konflikt) {
                if ((int) $konflikt['id_pracownika'] === $idPracownika) {
                    throw new RuntimeException('Ten pracownik ma już zaplanowany urlop obejmujący podany termin.');
                }
            }
            if ($konflikty) {
                throw new RuntimeException('W tym terminie inny pracownik tego salonu ma już zaplanowany urlop.');
            }

            // Obie daty są wliczone: sprawdzamy do północy PO ostatnim dniu.
            // Przyszły moduł rezerwacji musi również blokować wiersz pracownika
            // i sprawdzać zaplanowane urlopy przed zapisem/przesunięciem wizyty.
            $zapytanie = $pdo->prepare(
                "SELECT nr_rezerwacji, data_rozpoczecia FROM rezerwacje
                 WHERE id_pracownika = ? AND status IN ('oczekujaca', 'potwierdzona')
                   AND data_rozpoczecia < ? AND data_zakonczenia > ?
                 ORDER BY data_rozpoczecia LIMIT 1 FOR UPDATE"
            );
            $zapytanie->execute([$idPracownika, $do->modify('+1 day')->format('Y-m-d H:i:s'), $od->format('Y-m-d H:i:s')]);
            $wizyta = $zapytanie->fetch();
            if ($wizyta) {
                $termin = (new DateTimeImmutable($wizyta['data_rozpoczecia']))->format('d.m.Y H:i');
                throw new RuntimeException('Urlop koliduje z rezerwacją nr ' . $wizyta['nr_rezerwacji'] .
                    ' (' . $termin . '). Najpierw przełóż lub anuluj tę wizytę.');
            }
            if ($idUrlopu) {
                $zapytanie = $pdo->prepare('UPDATE urlopy SET data_od = ?, data_do = ? WHERE id_urlopu = ?');
                $zapytanie->execute([$formularz['data_od'], $formularz['data_do'], $idUrlopu]);
            } else {
                $zapytanie = $pdo->prepare("INSERT INTO urlopy (id_pracownika, data_od, data_do, status) VALUES (?, ?, ?, 'zaplanowany')");
                $zapytanie->execute([$idPracownika, $formularz['data_od'], $formularz['data_do']]);
            }
        }
        $pdo->commit();
        $_SESSION['urlopy_komunikat'] = $akcja === 'anuluj' ? 'Anulowano urlop. Historia została zachowana.' : 'Zapisano urlop.';
        przekieruj('/admin/urlopy.php');
        exit;
    } catch (PDOException $wyjatek) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $stan = (string) ($wyjatek->errorInfo[0] ?? $wyjatek->getCode());
        $kod = (int) ($wyjatek->errorInfo[1] ?? 0);
        // Znane komunikaty wyzwalaczy tłumaczymy, bez ujawniania zapytań SQL.
        $wiadomosc = (string) ($wyjatek->errorInfo[2] ?? '');
        if ($stan === '45000') {
            if (strpos($wiadomosc, 'wizyty') !== false) {
                $blad = 'Pracownik ma w tym okresie zaplanowane wizyty. Najpierw przełóż lub anuluj kolidujące rezerwacje.';
            } elseif (strpos($wiadomosc, 'salonie') !== false) {
                $blad = 'W tym terminie pracownik tego salonu ma już zaplanowany urlop.';
            } elseif (strpos($wiadomosc, 'Koniec urlopu') !== false) {
                $blad = 'Data końcowa nie może być wcześniejsza niż początkowa.';
            } else {
                $blad = 'Baza zablokowała zmianę urlopu z powodu konfliktu danych.';
            }
        } elseif ($stan === '40001' || in_array($kod, [1205, 1213], true)) {
            $blad = 'Inna osoba równocześnie zmieniała dane. Spróbuj zapisać ponownie.';
        } else {
            $blad = 'Nie udało się zapisać urlopu. Spróbuj ponownie.';
        }
        error_log('Urlopy: zapis, SQLSTATE ' . $stan . ', kod ' . $kod);
    } catch (RuntimeException $wyjatek) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $blad = $wyjatek->getMessage();
    }
}

$pracownicy = [];
$urlopy = [];
$odczytOK = false;
$poprzedniaOd = $czyPost ? urlopTekst($_POST, 'poprzednia_data_od') : '';
$poprzedniaDo = $czyPost ? urlopTekst($_POST, 'poprzednia_data_do') : '';
try {
    $pracownicy = $pdo->query(
        'SELECT p.id_pracownika, p.aktywnosc, u.imie, u.nazwisko, s.nazwa AS salon, s.miasto
         FROM pracownicy p JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika
         JOIN salony s ON s.id_salonu = p.id_salonu ORDER BY u.nazwisko, u.imie, p.id_pracownika'
    )->fetchAll();
    $urlopy = $pdo->query(
        'SELECT w.*, u.imie, u.nazwisko, s.nazwa AS salon, s.miasto
         FROM urlopy w JOIN pracownicy p ON p.id_pracownika = w.id_pracownika
         JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika
         JOIN salony s ON s.id_salonu = p.id_salonu ORDER BY w.data_od DESC, w.id_urlopu DESC'
    )->fetchAll();
    $odczytOK = true;
    if (!$czyPost && isset($_GET['edytuj'])) {
        $idEdycji = urlopId($_GET['edytuj']);
        $znaleziono = false;
        foreach ($urlopy as $wiersz) {
            if ((int) $wiersz['id_urlopu'] === $idEdycji && $wiersz['status'] === 'zaplanowany') {
                $formularz = ['id_urlopu' => $idEdycji, 'id_pracownika' => (string) $wiersz['id_pracownika'],
                    'data_od' => $wiersz['data_od'], 'data_do' => $wiersz['data_do']];
                $poprzedniaOd = $wiersz['data_od'];
                $poprzedniaDo = $wiersz['data_do'];
                $znaleziono = true;
                break;
            }
        }
        if (!$znaleziono) { $blad = 'Nie znaleziono zaplanowanego urlopu do edycji.'; }
    }
} catch (PDOException $wyjatek) {
    $blad = 'Nie udało się pobrać danych urlopów.';
    error_log('Urlopy: odczyt, SQLSTATE ' . $wyjatek->getCode());
}
$menu = ['index.php' => 'Podsumowanie', 'kategorie.php' => 'Kategorie', 'salony.php' => 'Salony',
    'uslugi.php' => 'Usługi', 'pracownicy.php' => 'Pracownicy', 'dostepnosc.php' => 'Dostępność',
    'urlopy.php' => 'Urlopy','kalendarz.php' => 'Kalendarz', 'rezerwacje.php' => 'Rezerwacje'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Urlopy — Reserve Beauty</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin2.css">
    <style>
        #urlopy .formularz { display: grid; gap: 16px; max-width: 680px; justify-content: stretch; }
        #urlopy select, #urlopy input[type="date"] { box-sizing: border-box; width: 100%; padding: 12px; border: 1px solid #d9c5ce; border-radius: 9px; background: white; font: inherit; }
        #urlopy .akcje { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        #urlopy .akcje form { display: block; margin: 0; }
        #urlopy .akcje a { padding: 10px 16px; border-radius: 9px; background: #f5e8ee; color: #873b57; text-decoration: none; font-weight: bold; }
        #urlopy td { vertical-align: middle; }
    </style>
</head>
<body class="strona-konta">
    <nav class="admin-menu" aria-label="Menu administratora">
        <ul class="admin-menu_lista">
            <?php foreach ($menu as $plik => $etykieta): ?>
                <li><a href="<?= e(BASE_URL . '/admin/' . $plik) ?>" <?= $plik === 'urlopy.php' ? 'aria-current="page"' : '' ?>><?= e($etykieta) ?></a></li>
            <?php endforeach; ?>
            <li><a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a></li>
        </ul>
    </nav>
    <main id="urlopy">
        <section class="karta" id="formularz">
            <h1><?= $formularz['id_urlopu'] ? 'Edytuj urlop' : 'Dodaj urlop' ?></h1>
            <p class="pomoc">Obie daty są wliczone w urlop. W jednym salonie w danym terminie może urlopować tylko jedna osoba.</p>
            <?php if ($komunikat): ?><p class="ok" role="status"><?= e($komunikat) ?></p><?php endif; ?>
            <?php if ($blad): ?><p class="blad" role="alert"><?= e($blad) ?></p><?php endif; ?>
            <form class="formularz" method="post" action="<?= e(BASE_URL) ?>/admin/urlopy.php#formularz">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="akcja" value="zapisz">
                <input type="hidden" name="id_urlopu" value="<?= (int) $formularz['id_urlopu'] ?>">
                <input type="hidden" name="poprzednia_data_od" value="<?= e($poprzedniaOd) ?>">
                <input type="hidden" name="poprzednia_data_do" value="<?= e($poprzedniaDo) ?>">
                <label>Pracownik
                    <select name="id_pracownika" required <?= $formularz['id_urlopu'] ? 'disabled' : '' ?>>
                        <option value="">Wybierz pracownika</option>
                        <?php foreach ($pracownicy as $osoba): ?>
                            <option value="<?= (int) $osoba['id_pracownika'] ?>" <?= (string) $osoba['id_pracownika'] === $formularz['id_pracownika'] ? 'selected' : '' ?>><?= e($osoba['imie'] . ' ' . $osoba['nazwisko'] . ' — ' . $osoba['salon'] . ', ' . $osoba['miasto']) ?><?= (int) $osoba['aktywnosc'] === 1 ? '' : ' (nieaktywny)' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($formularz['id_urlopu']): ?>
                    <input type="hidden" name="id_pracownika" value="<?= (int) $formularz['id_pracownika'] ?>">
                    <p class="pomoc">Edytujesz daty urlopu tej osoby. Dla innego pracownika dodaj osobny urlop.</p>
                <?php endif; ?>
                <div class="daty">
                    <label>Data od<input type="date" name="data_od" min="1000-01-01" max="9999-12-30" required value="<?= e($formularz['data_od']) ?>"></label>
                    <label>Data do<input type="date" name="data_do" min="1000-01-01" max="9999-12-30" required value="<?= e($formularz['data_do']) ?>"></label>
                </div>
                <div class="akcje">
                    <button type="submit" <?= !$odczytOK || !$pracownicy ? 'disabled' : '' ?>><?= $formularz['id_urlopu'] ? 'Zapisz zmiany' : 'Dodaj urlop' ?></button>
                    <?php if ($formularz['id_urlopu']): ?><a href="<?= e(BASE_URL) ?>/admin/urlopy.php">Anuluj edycję</a><?php endif; ?>
                </div>
            </form>
            <?php if ($odczytOK && !$pracownicy): ?><p>Najpierw dodaj pracownika w zakładce Pracownicy.</p><?php endif; ?>
        </section>
        <section class="karta">
            <h2>Lista urlopów</h2>
            <div class="tabela">
                <table>
                    <thead><tr><th>Pracownik</th><th>Salon</th><th>Od</th><th>Do</th><th>Status</th><th>Działania</th></tr></thead>
                    <tbody>
                        <?php foreach ($urlopy as $wiersz): ?>
                            <tr>
                                <td><?= e($wiersz['imie'] . ' ' . $wiersz['nazwisko']) ?></td>
                                <td><?= e($wiersz['salon'] . ' — ' . $wiersz['miasto']) ?></td>
                                <td><?= e(urlopPokazDate($wiersz['data_od'])) ?></td>
                                <td><?= e(urlopPokazDate($wiersz['data_do'])) ?></td>
                                <td><span class="status <?= $wiersz['status'] === 'anulowany' ? 'anulowany' : '' ?>"><?= $wiersz['status'] === 'anulowany' ? 'Anulowany' : 'Zaplanowany' ?></span></td>
                                <td>
                                    <?php if ($wiersz['status'] === 'zaplanowany'): ?>
                                        <div class="akcje">
                                            <a href="<?= e(BASE_URL) ?>/admin/urlopy.php?edytuj=<?= (int) $wiersz['id_urlopu'] ?>#formularz">Edytuj</a>
                                            <form method="post" action="<?= e(BASE_URL) ?>/admin/urlopy.php" onsubmit="return confirm('Czy anulować ten urlop? Wpis pozostanie w historii.');">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="akcja" value="anuluj">
                                                <input type="hidden" name="id_urlopu" value="<?= (int) $wiersz['id_urlopu'] ?>">
                                                <input type="hidden" name="id_pracownika" value="<?= (int) $wiersz['id_pracownika'] ?>">
                                                <input type="hidden" name="poprzednia_data_od" value="<?= e($wiersz['data_od']) ?>">
                                                <input type="hidden" name="poprzednia_data_do" value="<?= e($wiersz['data_do']) ?>">
                                                <button type="submit">Anuluj</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="pomoc">Historia — nie blokuje terminów</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$urlopy): ?><tr><td colspan="6"><?= $odczytOK ? 'Nie dodano jeszcze urlopów.' : 'Lista urlopów jest niedostępna.' ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
