<?php
// Powtarzalny grafik: 1 = poniedziałek, 7 = niedziela.
require_once __DIR__ . '/../includes/auth.php';
wymagajRoli('admin');

function grafikTekst(array $dane, string $klucz): string
{
    return isset($dane[$klucz]) && is_string($dane[$klucz]) ? trim($dane[$klucz]) : '';
}

function grafikId($wartosc): int
{
    if (!is_string($wartosc) && !is_int($wartosc)) {
        return 0;
    }
    return (int) (filter_var($wartosc, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647]
    ]) ?: 0);
}

// Łączymy również stykające się przedziały, np. 08–12 i 12–16.
function grafikObejmuje(array $przedzialy, string $poczatek, string $koniec): bool
{
    $pokrytoDo = $poczatek;
    foreach ($przedzialy as $przedzial) {
        if ($przedzial['czas_zakonczenia'] <= $pokrytoDo) {
            continue;
        }
        if ($przedzial['czas_rozpoczecia'] > $pokrytoDo) {
            return false;
        }
        $pokrytoDo = $przedzial['czas_zakonczenia'];
        if ($pokrytoDo >= $koniec) {
            return true;
        }
    }
    return false;
}

$dni = [1 => 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota', 'Niedziela'];
$blad = '';
$komunikat = $_SESSION['grafik_komunikat'] ?? '';
unset($_SESSION['grafik_komunikat']);
$czyPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$idPracownika = grafikId($czyPost ? ($_POST['id_pracownika'] ?? null) : ($_GET['id_pracownika'] ?? null));
$formularz = ['id' => 0, 'dzien_tygodnia' => '1', 'od' => '08:00', 'do' => '16:00'];
$pracownicy = [];
$przedzialy = [];
$pracownik = null;
$odczytOK = false;

try {
    $pracownicy = $pdo->query(
        'SELECT p.id_pracownika, p.aktywnosc, u.imie, u.nazwisko, s.nazwa AS salon, s.miasto
         FROM pracownicy p
         JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika
         JOIN salony s ON s.id_salonu = p.id_salonu
         ORDER BY u.nazwisko, u.imie, p.id_pracownika'
    )->fetchAll();
    foreach ($pracownicy as $osoba) {
        if ((int) $osoba['id_pracownika'] === $idPracownika) {
            $pracownik = $osoba;
            break;
        }
    }
    $odczytOK = true;
} catch (PDOException $wyjatek) {
    error_log('Grafik: odczyt pracowników, SQLSTATE ' . $wyjatek->getCode());
    $blad = 'Nie udało się pobrać pracowników z bazy.';
}

if ($czyPost && $odczytOK) {
    $akcja = grafikTekst($_POST, 'akcja');
    $id = grafikId($_POST['id'] ?? null);
    if ($akcja === 'zapisz') {
        $formularz = [
            'id' => $id,
            'dzien_tygodnia' => grafikTekst($_POST, 'dzien_tygodnia'),
            'od' => grafikTekst($_POST, 'od'),
            'do' => grafikTekst($_POST, 'do')
        ];
    }
    try {
        if (!sprawdzCsrf()) {
            throw new RuntimeException('Formularz wygasł. Odśwież stronę i spróbuj ponownie.');
        }
        if (!$pracownik) {
            throw new RuntimeException('Wybierz istniejącego pracownika.');
        }
        if (!in_array($akcja, ['zapisz', 'usun'], true)) {
            throw new RuntimeException('Nieprawidłowe działanie.');
        }
        if (($akcja === 'usun' && !$id) || ($akcja === 'zapisz' && grafikTekst($_POST, 'id') !== '0' && !$id)) {
            throw new RuntimeException('Nieprawidłowy identyfikator przedziału.');
        }
        $dzien = grafikId($formularz['dzien_tygodnia']);
        $od = $formularz['od'];
        $do = $formularz['do'];
        if ($akcja === 'zapisz') {
            if (!isset($dni[$dzien])) {
                throw new RuntimeException('Wybierz dzień od poniedziałku do niedzieli.');
            }
            if (!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $od) ||
                !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $do)) {
                throw new RuntimeException('Podaj poprawne godziny w formacie GG:MM.');
            }
            if ($do <= $od) {
                throw new RuntimeException('Koniec pracy musi być późniejszy niż początek.');
            }
        }

        $pdo->beginTransaction();
        // Blokada pracownika zapobiega równoczesnym zmianom jego grafiku.
        // Moduł rezerwacji również powinien blokować ten wiersz i sprawdzać grafik
        // przed utworzeniem lub przesunięciem wizyty.
        $zapytanie = $pdo->prepare('SELECT id_pracownika FROM pracownicy WHERE id_pracownika = ? FOR UPDATE');
        $zapytanie->execute([$idPracownika]);
        if (!$zapytanie->fetch()) {
            throw new RuntimeException('Pracownik już nie istnieje.');
        }
        $zapytanie = $pdo->prepare('SELECT * FROM dostepnosc_pracownikow WHERE id_pracownika = ? ORDER BY dzien_tygodnia, czas_rozpoczecia FOR UPDATE');
        $zapytanie->execute([$idPracownika]);
        $stare = $zapytanie->fetchAll();
        $nowe = [];
        $zmienianeDni = [];
        $znaleziono = false;
        foreach ($stare as $wiersz) {
            if ((int) $wiersz['id'] === $id) {
                $znaleziono = true;
                $zmienianeDni[] = (int) $wiersz['dzien_tygodnia'];
            } else {
                $nowe[] = $wiersz;
            }
        }
        if ($id && !$znaleziono) {
            throw new RuntimeException('Nie znaleziono tego przedziału u wybranego pracownika.');
        }
        if ($akcja === 'zapisz') {
            $od .= ':00';
            $do .= ':00';
            foreach ($nowe as $wiersz) {
                if ((int) $wiersz['dzien_tygodnia'] === $dzien &&
                    $od < $wiersz['czas_zakonczenia'] && $do > $wiersz['czas_rozpoczecia']) {
                    throw new RuntimeException('Ten przedział nakłada się na inne godziny pracy w wybranym dniu.');
                }
            }
            $nowe[] = ['dzien_tygodnia' => $dzien, 'czas_rozpoczecia' => $od, 'czas_zakonczenia' => $do];
            $zmienianeDni[] = $dzien;
        }
        usort($nowe, function ($a, $b) {
            return strcmp($a['czas_rozpoczecia'], $b['czas_rozpoczecia']);
        });

        // Sprawdzamy przyszłe i trwające wizyty w dniach objętych zmianą.
        $strefa = new DateTimeZone('Europe/Warsaw');
        $teraz = new DateTimeImmutable('now', $strefa);
        $zapytanie = $pdo->prepare(
            "SELECT nr_rezerwacji, data_rozpoczecia, data_zakonczenia
             FROM rezerwacje WHERE id_pracownika = ?
             AND status IN ('oczekujaca', 'potwierdzona') AND data_zakonczenia > ?
             ORDER BY data_rozpoczecia FOR UPDATE"
        );
        $zapytanie->execute([$idPracownika, $teraz->format('Y-m-d H:i:s')]);
        foreach ($zapytanie->fetchAll() as $wizyta) {
            $start = new DateTimeImmutable($wizyta['data_rozpoczecia'], $strefa);
            $koniec = new DateTimeImmutable($wizyta['data_zakonczenia'], $strefa);
            $dzienWizyty = (int) $start->format('N');
            if (!in_array($dzienWizyty, $zmienianeDni, true) &&
                !in_array((int) $koniec->format('N'), $zmienianeDni, true)) {
                continue;
            }
            $godziny = array_values(array_filter($nowe, function ($wiersz) use ($dzienWizyty) {
                return (int) $wiersz['dzien_tygodnia'] === $dzienWizyty;
            }));
            if ($start->format('Y-m-d') !== $koniec->format('Y-m-d') ||
                !grafikObejmuje($godziny, $start->format('H:i:s'), $koniec->format('H:i:s'))) {
                throw new RuntimeException(
                    'Nie można zmienić grafiku. Rezerwacja nr ' . $wizyta['nr_rezerwacji'] .
                    ' (' . $start->format('d.m.Y H:i') . ' – ' . $koniec->format('d.m.Y H:i') .
                    ') znalazłaby się poza godzinami pracy. Najpierw przełóż lub anuluj tę wizytę.'
                );
            }
        }

        if ($akcja === 'usun') {
            $zapytanie = $pdo->prepare('DELETE FROM dostepnosc_pracownikow WHERE id = ? AND id_pracownika = ?');
            $zapytanie->execute([$id, $idPracownika]);
        } elseif ($id) {
            $zapytanie = $pdo->prepare('UPDATE dostepnosc_pracownikow SET dzien_tygodnia = ?, czas_rozpoczecia = ?, czas_zakonczenia = ? WHERE id = ? AND id_pracownika = ?');
            $zapytanie->execute([$dzien, $od, $do, $id, $idPracownika]);
        } else {
            $zapytanie = $pdo->prepare('INSERT INTO dostepnosc_pracownikow (id_pracownika, dzien_tygodnia, czas_rozpoczecia, czas_zakonczenia) VALUES (?, ?, ?, ?)');
            $zapytanie->execute([$idPracownika, $dzien, $od, $do]);
        }
        $pdo->commit();
        $_SESSION['grafik_komunikat'] = $akcja === 'usun' ? 'Usunięto przedział pracy.' : 'Zapisano grafik.';
        przekieruj('/admin/dostepnosc.php?id_pracownika=' . $idPracownika);
        exit;
    } catch (PDOException $wyjatek) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('Grafik: zapis, SQLSTATE ' . $wyjatek->getCode());
        $blad = 'Nie udało się zapisać zmiany. Spróbuj ponownie.';
    } catch (RuntimeException $wyjatek) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $blad = $wyjatek->getMessage();
    }
}

if ($pracownik) {
    try {
        $zapytanie = $pdo->prepare('SELECT * FROM dostepnosc_pracownikow WHERE id_pracownika = ? ORDER BY dzien_tygodnia, czas_rozpoczecia');
        $zapytanie->execute([$idPracownika]);
        $przedzialy = $zapytanie->fetchAll();
        if (!$czyPost && isset($_GET['edytuj'])) {
            $idEdycji = grafikId($_GET['edytuj']);
            $znaleziono = false;
            foreach ($przedzialy as $wiersz) {
                if ((int) $wiersz['id'] === $idEdycji) {
                    $formularz = ['id' => $idEdycji, 'dzien_tygodnia' => (string) $wiersz['dzien_tygodnia'],
                        'od' => substr($wiersz['czas_rozpoczecia'], 0, 5), 'do' => substr($wiersz['czas_zakonczenia'], 0, 5)];
                    $znaleziono = true;
                }
            }
            if (!$znaleziono) { $blad = 'Nie znaleziono przedziału do edycji.'; }
        }
    } catch (PDOException $wyjatek) {
        $odczytOK = false;
        $blad = 'Nie udało się pobrać grafiku.';
        error_log('Grafik: odczyt, SQLSTATE ' . $wyjatek->getCode());
    }
} elseif ($idPracownika && !$blad) {
    $blad = 'Wybrany pracownik nie istnieje.';
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
    <title>Grafik tygodniowy — Reserve Beauty</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin2.css">
</head>
<body class="strona-konta">
    <nav class="admin-menu" aria-label="Menu administratora">
        <ul class="admin-menu_lista">
            <?php foreach ($menu as $plik => $etykieta): ?>
                <li><a href="<?= e(BASE_URL . '/admin/' . $plik) ?>" <?= $plik === 'dostepnosc.php' ? 'aria-current="page"' : '' ?>><?= e($etykieta) ?></a></li>
            <?php endforeach; ?>
            <li><a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a></li>
        </ul>
    </nav>
    <main id="grafik">
        <section class="karta">
            <h1>Grafik tygodniowy</h1>
            <p class="pomoc">Godziny powtarzają się co tydzień. 1 oznacza poniedziałek, a 7 niedzielę.</p>
            <?php if ($komunikat): ?><p class="ok" role="status"><?= e($komunikat) ?></p><?php endif; ?>
            <?php if ($blad): ?><p class="blad" role="alert"><?= e($blad) ?></p><?php endif; ?>
            <form class="grafik-form" method="get" action="<?= e(BASE_URL) ?>/admin/dostepnosc.php">
                <label>Pracownik
                    <select name="id_pracownika" required>
                        <option value="">Wybierz pracownika</option>
                        <?php foreach ($pracownicy as $osoba): ?>
                            <option value="<?= (int) $osoba['id_pracownika'] ?>" <?= (int) $osoba['id_pracownika'] === $idPracownika ? 'selected' : '' ?>><?= e($osoba['imie'] . ' ' . $osoba['nazwisko'] . ' — ' . $osoba['salon'] . ', ' . $osoba['miasto']) ?><?= (int) $osoba['aktywnosc'] === 1 ? '' : ' (nieaktywny)' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div><button type="submit" <?= !$pracownicy ? 'disabled' : '' ?>>Pokaż grafik</button></div>
            </form>
            <?php if ($odczytOK && !$pracownicy): ?><p>Najpierw dodaj pracownika w zakładce Pracownicy.</p><?php endif; ?>
        </section>
        <?php if ($pracownik && $odczytOK): ?>
            <section class="karta">
                <h2><?= e($pracownik['imie'] . ' ' . $pracownik['nazwisko']) ?></h2>
                <p><?= e($pracownik['salon'] . ' — ' . $pracownik['miasto']) ?></p>
                <div class="tabela">
                    <table>
                        <thead><tr><th>Dzień tygodnia</th><th>Od</th><th>Do</th><th>Działania</th></tr></thead>
                        <tbody>
                            <?php foreach ($przedzialy as $wiersz): ?>
                                <tr>
                                    <td><?= e($dni[(int) $wiersz['dzien_tygodnia']] ?? 'Nieprawidłowy dzień') ?></td>
                                    <td><?= e(substr($wiersz['czas_rozpoczecia'], 0, 5)) ?></td>
                                    <td><?= e(substr($wiersz['czas_zakonczenia'], 0, 5)) ?></td>
                                    <td><div class="grafik-akcje">
                                        <a href="<?= e(BASE_URL) ?>/admin/dostepnosc.php?id_pracownika=<?= $idPracownika ?>&amp;edytuj=<?= (int) $wiersz['id'] ?>#formularz">Edytuj</a>
                                        <form method="post" action="<?= e(BASE_URL) ?>/admin/dostepnosc.php" onsubmit="return confirm('Czy usunąć ten przedział pracy?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="akcja" value="usun">
                                            <input type="hidden" name="id_pracownika" value="<?= $idPracownika ?>">
                                            <input type="hidden" name="id" value="<?= (int) $wiersz['id'] ?>">
                                            <button type="submit">Usuń</button>
                                        </form>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$przedzialy): ?><tr><td colspan="4">Ten pracownik nie ma jeszcze ustalonych godzin pracy.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="karta" id="formularz">
                <h2><?= $formularz['id'] ? 'Edytuj przedział pracy' : 'Dodaj przedział pracy' ?></h2>
                <form class="grafik-form" method="post" action="<?= e(BASE_URL) ?>/admin/dostepnosc.php">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="akcja" value="zapisz">
                    <input type="hidden" name="id" value="<?= (int) $formularz['id'] ?>">
                    <input type="hidden" name="id_pracownika" value="<?= $idPracownika ?>">
                    <label>Dzień tygodnia
                        <select name="dzien_tygodnia" required>
                            <?php foreach ($dni as $numer => $nazwa): ?>
                                <option value="<?= $numer ?>" <?= (string) $numer === $formularz['dzien_tygodnia'] ? 'selected' : '' ?>><?= $numer ?> — <?= e($nazwa) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="godziny">
                        <label>Początek pracy<input type="time" name="od" step="60" required value="<?= e($formularz['od']) ?>"></label>
                        <label>Koniec pracy<input type="time" name="do" step="60" required value="<?= e($formularz['do']) ?>"></label>
                    </div>
                    <p class="pomoc">Przerwę dodaj jako dwa oddzielne przedziały, np. 08:00–12:00 i 13:00–16:00. Przedział musi mieścić się w jednym dniu.</p>
                    <div class="grafik-akcje">
                        <button type="submit">Zapisz</button>
                        <?php if ($formularz['id']): ?><a href="<?= e(BASE_URL) ?>/admin/dostepnosc.php?id_pracownika=<?= $idPracownika ?>#formularz">Anuluj edycję</a><?php endif; ?>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>