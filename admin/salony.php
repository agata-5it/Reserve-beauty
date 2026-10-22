<?php

// Logowanie, połączenie PDO i funkcje e(), przekieruj(), sprawdzCsrf().
require_once __DIR__ . '/../includes/auth.php';
$uzytkownik = wymagajRoli('admin');

// W aktualnym auth.php nie ma funkcji poleTekstowe().
// Własna funkcja odczytuje tekst z formularza i odrzuca tablice.
function salonyPoleTekstowe(string $nazwa): string
{
    $wartosc = $_POST[$nazwa] ?? '';
    return is_string($wartosc) ? $wartosc : '';
}

$blad = '';
$komunikat = $_SESSION['salony_komunikat'] ?? '';
unset($_SESSION['salony_komunikat']);

$formularz = ['id' => 0, 'nazwa' => '', 'opis' => '', 'miasto' => '', 'ulica' => '', 'nr_ulicy' => '', 'aktywnosc' => 1];
$blokadaUsuniecia = 'Nie można usunąć salonu z przypisanymi pracownikami lub usługami. Możesz go dezaktywować.';

// UWAGA: formularz nowych rezerwacji musi sprawdzać salony.aktywnosc = 1.
// Dezaktywacja zmienia tylko status; zachowuje pracowników, usługi i historię.

// 1. Obsługa formularzy. Każda zmiana danych wymaga POST i tokenu CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akcja = salonyPoleTekstowe('akcja');
    $id = filter_var(salonyPoleTekstowe('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if (!sprawdzCsrf()) {
        $blad = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
    } elseif (!in_array($akcja, ['dodaj', 'edytuj', 'aktywnosc', 'usun'], true)) {
        $blad = 'Nieprawidłowa operacja.';
    } elseif ($akcja !== 'dodaj' && $id === false) {
        $blad = 'Nieprawidłowy numer salonu.';
    } else {
        // Dane pozostają w formularzu, jeżeli walidacja się nie powiedzie.
        if ($akcja === 'dodaj' || $akcja === 'edytuj') {
            $formularz = [
                'id' => $akcja === 'edytuj' ? $id : 0,
                'nazwa' => trim(salonyPoleTekstowe('nazwa')),
                'opis' => trim(salonyPoleTekstowe('opis')),
                'miasto' => trim(salonyPoleTekstowe('miasto')),
                'ulica' => trim(salonyPoleTekstowe('ulica')),
                'nr_ulicy' => trim(salonyPoleTekstowe('nr_ulicy')),
                'aktywnosc' => salonyPoleTekstowe('aktywnosc') === '1' ? 1 : 0,
            ];

            // Limity odpowiadają kolumnom w Waszej bazie.
            $limity = ['nazwa' => 100, 'miasto' => 100, 'ulica' => 200, 'nr_ulicy' => 8];
            $etykiety = ['nazwa' => 'Nazwa', 'miasto' => 'Miasto', 'ulica' => 'Ulica', 'nr_ulicy' => 'Numer budynku'];
            foreach ($limity as $pole => $limit) {
                $tekst = $formularz[$pole];
                if (preg_match('//u', $tekst) !== 1) {
                    $blad = $etykiety[$pole] . ': tekst zawiera nieprawidłowe znaki.';
                    break;
                }
                if (preg_match('/^[\s\p{Z}]*$/u', $tekst)) {
                    $blad = 'Uzupełnij pole: ' . $etykiety[$pole] . '.';
                    break;
                }
                if (preg_match_all('/./us', $tekst) > $limit) {
                    $blad = $etykiety[$pole] . ': maksymalnie ' . $limit . ' znaków.';
                    break;
                }
            }
            if ($blad === '' && preg_match('//u', $formularz['opis']) !== 1) {
                $blad = 'Opis zawiera nieprawidłowe znaki.';
            } elseif ($blad === '' && strlen($formularz['opis']) > 65535) {
                $blad = 'Opis jest za długi. Skróć go.';
            }
        }

        if ($blad === '') {
            try {
                $pdo->beginTransaction();

                // Blokujemy wybrany rekord na czas zmiany lub usunięcia.
                if ($akcja !== 'dodaj') {
                    $zapytanie = $pdo->prepare('SELECT id_salonu FROM salony WHERE id_salonu = ? FOR UPDATE');
                    $zapytanie->execute([$id]);
                    if (!$zapytanie->fetch()) {
                        throw new RuntimeException('Ten salon już nie istnieje.');
                    }
                }

                if ($akcja === 'dodaj') {
                    $zapytanie = $pdo->prepare(
                        'INSERT INTO salony (nazwa, opis, miasto, ulica, nr_ulicy, aktywnosc) VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $zapytanie->execute([
                        $formularz['nazwa'], $formularz['opis'] === '' ? null : $formularz['opis'],
                        $formularz['miasto'], $formularz['ulica'], $formularz['nr_ulicy'], $formularz['aktywnosc']
                    ]);
                    $sukces = 'Dodano salon.';
                } elseif ($akcja === 'edytuj') {
                    $zapytanie = $pdo->prepare(
                        'UPDATE salony SET nazwa = ?, opis = ?, miasto = ?, ulica = ?, nr_ulicy = ?, aktywnosc = ? WHERE id_salonu = ?'
                    );
                    $zapytanie->execute([
                        $formularz['nazwa'], $formularz['opis'] === '' ? null : $formularz['opis'],
                        $formularz['miasto'], $formularz['ulica'], $formularz['nr_ulicy'], $formularz['aktywnosc'], $id
                    ]);
                    $sukces = 'Zapisano zmiany salonu.';
                } elseif ($akcja === 'aktywnosc') {
                    $stan = salonyPoleTekstowe('stan');
                    if (!in_array($stan, ['0', '1'], true)) {
                        throw new RuntimeException('Nieprawidłowy stan salonu.');
                    }
                    $zapytanie = $pdo->prepare('UPDATE salony SET aktywnosc = ? WHERE id_salonu = ?');
                    $zapytanie->execute([(int) $stan, $id]);
                    $sukces = $stan === '1' ? 'Włączono salon.' : 'Wyłączono salon.';
                } else {
                    // Baza ma ON DELETE CASCADE. Dlatego przed usunięciem
                    // koniecznie sprawdzamy pracowników i usługi (także nieaktywne).
                    // Blokada salonu powyżej chroni też przed równoczesnym
                    // dopisaniem powiązania przez klucz obcy w InnoDB.
                    $zapytanie = $pdo->prepare('SELECT id_pracownika FROM pracownicy WHERE id_salonu = ? LIMIT 1 FOR UPDATE');
                    $zapytanie->execute([$id]);
                    if ($zapytanie->fetch()) {
                        throw new RuntimeException($blokadaUsuniecia);
                    }
                    $zapytanie = $pdo->prepare('SELECT id_uslugi FROM uslugi WHERE id_salonu = ? LIMIT 1 FOR UPDATE');
                    $zapytanie->execute([$id]);
                    if ($zapytanie->fetch()) {
                        throw new RuntimeException($blokadaUsuniecia);
                    }
                    $zapytanie = $pdo->prepare('DELETE FROM salony WHERE id_salonu = ?');
                    $zapytanie->execute([$id]);
                    $sukces = 'Usunięto salon.';
                }

                $pdo->commit();
                $_SESSION['salony_komunikat'] = $sukces;
                // Odświeżenie strony nie powtórzy zapisu.
                przekieruj('/admin/salony.php');
            } catch (PDOException $wyjatek) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Salony: SQLSTATE ' . $wyjatek->getCode());
                $blad = $akcja === 'usun' && (int) ($wyjatek->errorInfo[1] ?? 0) === 1451
                    ? $blokadaUsuniecia
                    : 'Nie udało się zapisać zmian. Spróbuj ponownie.';
            } catch (RuntimeException $wyjatek) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $blad = $wyjatek->getMessage();
            }
        }
    }
}

// 2. Pobranie salonów do edycji i listy wszystkich salonów.
$salony = [];
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edytuj'])) {
        $idEdycji = is_string($_GET['edytuj'])
            ? filter_var($_GET['edytuj'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($idEdycji === false) {
            $blad = 'Nieprawidłowy numer salonu.';
        } else {
            $zapytanie = $pdo->prepare('SELECT id_salonu AS id, nazwa, opis, miasto, ulica, nr_ulicy, aktywnosc FROM salony WHERE id_salonu = ?');
            $zapytanie->execute([$idEdycji]);
            $salon = $zapytanie->fetch();
            if ($salon) {
                $formularz = $salon;
            } else {
                $blad = 'Nie znaleziono salonu.';
            }
        }
    }
    $salony = $pdo->query('SELECT id_salonu AS id, nazwa, opis, miasto, ulica, nr_ulicy, aktywnosc FROM salony ORDER BY nazwa')->fetchAll();
} catch (PDOException $wyjatek) {
    error_log('Odczyt salonów: SQLSTATE ' . $wyjatek->getCode());
    $blad = 'Nie udało się pobrać salonów. Spróbuj ponownie później.';
}

$edycja = (int) $formularz['id'] > 0;
$menu = [
    'index.php' => 'Podsumowanie', 'kategorie.php' => 'Kategorie',
    'salony.php' => 'Salony', 'uslugi.php' => 'Usługi',
    'pracownicy.php' => 'Pracownicy', 'dostepnosc.php' => 'Dostępność',
    'urlopy.php' => 'Urlopy', 'rezerwacje.php' => 'Rezerwacje',
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salony — Reserve Beauty</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin2.css">
</head>
<body class="strona-konta">
    <nav class="admin-menu" aria-label="Menu administratora">
        <ul class="admin-menu_lista">
            <?php foreach ($menu as $plik => $etykieta): ?>
                <li><a href="<?= e(BASE_URL . '/admin/' . $plik) ?>" <?= $plik === 'salony.php' ? 'aria-current="page"' : '' ?>><?= e($etykieta) ?></a></li>
            <?php endforeach; ?>
            <li><a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a></li>
        </ul>
    </nav>

    <main class="panel">
        <div class="pole">
            <div>
                <p class="marka">RESERVE BEAUTY</p>
                <h1>Salony</h1>
            </div>
            <form action="<?= e(BASE_URL) ?>/wylogowanie.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <button type="submit">Wyloguj się</button>
            </form>
        </div>

        <?php if ($komunikat !== ''): ?>
            <p class="ok" role="status"><?= e($komunikat) ?></p>
        <?php endif; ?>
        <?php if ($blad !== ''): ?>
            <p class="blad" role="alert"><?= e($blad) ?></p>
        <?php endif; ?>

        <section class="karta" aria-labelledby="tytul-formularza">
            <h2 id="tytul-formularza"><?= $edycja ? 'Edytuj salon' : 'Dodaj salon' ?></h2>
            <form class="formularz" action="<?= e(BASE_URL) ?>/admin/salony.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="akcja" value="<?= $edycja ? 'edytuj' : 'dodaj' ?>">
                <input type="hidden" name="id" value="<?= (int) $formularz['id'] ?>">
                <label>Nazwa (wymagana, maks. 100 znaków)
                    <input type="text" name="nazwa" maxlength="100" required value="<?= e($formularz['nazwa']) ?>">
                </label>
                <label>Opis (opcjonalny)
                    <textarea name="opis" rows="4"><?= e($formularz['opis'] ?? '') ?></textarea>
                </label>
                <label>Miasto (maks. 100 znaków)
                    <input type="text" name="miasto" maxlength="100" required value="<?= e($formularz['miasto']) ?>">
                </label>
                <label>Ulica (maks. 200 znaków)
                    <input type="text" name="ulica" maxlength="200" required value="<?= e($formularz['ulica']) ?>">
                </label>
                <label>Numer budynku (np. 12A, maks. 8 znaków)
                    <input type="text" name="nr_ulicy" maxlength="8" required value="<?= e($formularz['nr_ulicy']) ?>">
                </label>
                <label class="wybor">
                    <input type="checkbox" name="aktywnosc" value="1" <?= (int) $formularz['aktywnosc'] === 1 ? 'checked' : '' ?>>
                    Salon aktywny
                </label>
                <div class="akcje">
                    <button type="submit"><?= $edycja ? 'Zapisz zmiany' : 'Dodaj salon' ?></button>
                    <?php if ($edycja): ?>
                        <a class="link" href="<?= e(BASE_URL) ?>/admin/salony.php">Anuluj edycję</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="karta" aria-labelledby="tytul-listy">
            <h2 id="tytul-listy">Lista salonów</h2>
            <div class="tabela">
                <table>
                    <thead><tr><th scope="col">Nazwa</th><th scope="col">Opis</th><th scope="col">Adres</th><th scope="col">Aktywność</th><th scope="col">Działania</th></tr></thead>
                    <tbody>
                    <?php foreach ($salony as $salon): ?>
                        <tr>
                            <td class="nazwa"><?= e($salon['nazwa']) ?></td>
                            <td class="opis"><?= e($salon['opis'] ?? '—') ?></td>
                            <td class="adres"><?= e($salon['miasto']) ?><br><?= e($salon['ulica'] . ' ' . $salon['nr_ulicy']) ?></td>
                            <td><?= (int) $salon['aktywnosc'] === 1 ? 'Aktywny' : 'Nieaktywny' ?></td>
                            <td>
                                <div class="akcje">
                                    <a class="link" href="<?= e(BASE_URL) ?>/admin/salony.php?edytuj=<?= (int) $salon['id'] ?>">Edytuj</a>
                                    <form action="<?= e(BASE_URL) ?>/admin/salony.php" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="akcja" value="aktywnosc">
                                        <input type="hidden" name="id" value="<?= (int) $salon['id'] ?>">
                                        <input type="hidden" name="stan" value="<?= (int) $salon['aktywnosc'] === 1 ? '0' : '1' ?>">
                                        <button type="submit"><?= (int) $salon['aktywnosc'] === 1 ? 'Wyłącz' : 'Włącz' ?></button>
                                    </form>
                                    <form action="<?= e(BASE_URL) ?>/admin/salony.php" method="post" onsubmit="return confirm('Czy na pewno usunąć ten salon?');">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="akcja" value="usun">
                                        <input type="hidden" name="id" value="<?= (int) $salon['id'] ?>">
                                        <button type="submit">Usuń</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$salony): ?>
                        <tr><td colspan="5">Brak salonów do wyświetlenia.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
