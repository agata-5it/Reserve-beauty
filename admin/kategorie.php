<?php

// Logowanie, połączenie PDO i funkcje e(), przekieruj(), sprawdzCsrf().
require_once __DIR__ . '/../includes/auth.php';
$uzytkownik = wymagajRoli('admin');

// W aktualnym auth.php nie ma funkcji poleTekstowe().
// Własna funkcja odczytuje tekst z formularza i odrzuca tablice.
function kategoriePoleTekstowe(string $nazwa): string
{
    $wartosc = $_POST[$nazwa] ?? '';
    return is_string($wartosc) ? $wartosc : '';
}

$blad = '';
$komunikat = $_SESSION['kategorie_komunikat'] ?? '';
unset($_SESSION['kategorie_komunikat']);

$formularz = ['id' => 0, 'nazwa' => '', 'opis' => '', 'aktywny' => 1];
$blokadaUsuniecia = 'Nie można usunąć kategorii przypisanej do usług. Możesz ją dezaktywować.';

// 1. Obsługa formularzy. Każda zmiana danych wymaga POST i tokenu CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akcja = kategoriePoleTekstowe('akcja');
    $id = filter_var(kategoriePoleTekstowe('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if (!sprawdzCsrf()) {
        $blad = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
    } elseif (!in_array($akcja, ['dodaj', 'edytuj', 'aktywnosc', 'usun'], true)) {
        $blad = 'Nieprawidłowa operacja.';
    } elseif ($akcja !== 'dodaj' && $id === false) {
        $blad = 'Nieprawidłowy numer kategorii.';
    } else {
        // Dane pozostają w formularzu, jeżeli walidacja się nie powiedzie.
        if ($akcja === 'dodaj' || $akcja === 'edytuj') {
            $formularz = [
                'id' => $akcja === 'edytuj' ? $id : 0,
                'nazwa' => trim(kategoriePoleTekstowe('nazwa')),
                'opis' => trim(kategoriePoleTekstowe('opis')),
                'aktywny' => kategoriePoleTekstowe('aktywny') === '1' ? 1 : 0,
            ];

            // VARCHAR(150): maksymalnie 150 znaków, również polskich.
            if (preg_match('//u', $formularz['nazwa']) !== 1
                || preg_match('//u', $formularz['opis']) !== 1) {
                $blad = 'Tekst zawiera nieprawidłowe znaki.';
            } elseif (preg_match('/^[\s\p{Z}]*$/u', $formularz['nazwa'])) {
                $blad = 'Podaj nazwę kategorii.';
            } elseif (preg_match_all('/./us', $formularz['nazwa']) > 150) {
                $blad = 'Nazwa może mieć maksymalnie 150 znaków.';
            } elseif (strlen($formularz['opis']) > 65535) {
                // Kolumna TEXT mieści maksymalnie 65 535 bajtów.
                $blad = 'Opis jest za długi. Skróć go.';
            }
        }

        if ($blad === '') {
            try {
                $pdo->beginTransaction();

                // Blokujemy wybrany rekord na czas zmiany lub usunięcia.
                if ($akcja !== 'dodaj') {
                    $zapytanie = $pdo->prepare('SELECT id FROM kategorie_uslug WHERE id = ? FOR UPDATE');
                    $zapytanie->execute([$id]);
                    if (!$zapytanie->fetch()) {
                        throw new RuntimeException('Ta kategoria już nie istnieje.');
                    }
                }

                if ($akcja === 'dodaj') {
                    $zapytanie = $pdo->prepare(
                        'INSERT INTO kategorie_uslug (nazwa, opis, aktywny) VALUES (?, ?, ?)'
                    );
                    $zapytanie->execute([
                        $formularz['nazwa'], $formularz['opis'] === '' ? null : $formularz['opis'], $formularz['aktywny']
                    ]);
                    $sukces = 'Dodano kategorię.';
                } elseif ($akcja === 'edytuj') {
                    $zapytanie = $pdo->prepare(
                        'UPDATE kategorie_uslug SET nazwa = ?, opis = ?, aktywny = ? WHERE id = ?'
                    );
                    $zapytanie->execute([
                        $formularz['nazwa'], $formularz['opis'] === '' ? null : $formularz['opis'], $formularz['aktywny'], $id
                    ]);
                    $sukces = 'Zapisano zmiany kategorii.';
                } elseif ($akcja === 'aktywnosc') {
                    $stan = kategoriePoleTekstowe('stan');
                    if (!in_array($stan, ['0', '1'], true)) {
                        throw new RuntimeException('Nieprawidłowy stan kategorii.');
                    }
                    $zapytanie = $pdo->prepare('UPDATE kategorie_uslug SET aktywny = ? WHERE id = ?');
                    $zapytanie->execute([(int) $stan, $id]);
                    $sukces = $stan === '1' ? 'Włączono kategorię.' : 'Wyłączono kategorię.';
                } else {
                    // Sprawdzamy także usługi nieaktywne.
                    $zapytanie = $pdo->prepare('SELECT id_uslugi FROM uslugi WHERE id_kategoria = ? LIMIT 1 FOR UPDATE');
                    $zapytanie->execute([$id]);
                    if ($zapytanie->fetch()) {
                        throw new RuntimeException($blokadaUsuniecia);
                    }
                    $zapytanie = $pdo->prepare('DELETE FROM kategorie_uslug WHERE id = ?');
                    $zapytanie->execute([$id]);
                    $sukces = 'Usunięto kategorię.';
                }

                $pdo->commit();
                $_SESSION['kategorie_komunikat'] = $sukces;
                // Odświeżenie strony nie powtórzy zapisu.
                przekieruj('/admin/kategorie.php');
            } catch (PDOException $wyjatek) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Kategorie: SQLSTATE ' . $wyjatek->getCode());
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

// 2. Pobranie kategorii do edycji i listy wszystkich kategorii.
$kategorie = [];
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edytuj'])) {
        $idEdycji = is_string($_GET['edytuj'])
            ? filter_var($_GET['edytuj'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($idEdycji === false) {
            $blad = 'Nieprawidłowy numer kategorii.';
        } else {
            $zapytanie = $pdo->prepare('SELECT id, nazwa, opis, aktywny FROM kategorie_uslug WHERE id = ?');
            $zapytanie->execute([$idEdycji]);
            $kategoria = $zapytanie->fetch();
            if ($kategoria) {
                $formularz = $kategoria;
            } else {
                $blad = 'Nie znaleziono kategorii.';
            }
        }
    }
    $kategorie = $pdo->query('SELECT id, nazwa, opis, aktywny FROM kategorie_uslug ORDER BY nazwa')->fetchAll();
} catch (PDOException $wyjatek) {
    error_log('Odczyt kategorii: SQLSTATE ' . $wyjatek->getCode());
    $blad = 'Nie udało się pobrać kategorii. Spróbuj ponownie później.';
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
    <title>Kategorie usług — Reserve Beauty</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin2.css">
</head>
<body class="strona-konta">
    <nav class="admin-menu" aria-label="Menu administratora">
        <ul class="admin-menu_lista">
            <?php foreach ($menu as $plik => $etykieta): ?>
                <li><a href="<?= e(BASE_URL . '/admin/' . $plik) ?>" <?= $plik === 'kategorie.php' ? 'aria-current="page"' : '' ?>><?= e($etykieta) ?></a></li>
            <?php endforeach; ?>
            <li><a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a></li>
        </ul>
    </nav>

    <main class="panel">
        <div class="pole">
            <div>
                <p class="marka">RESERVE BEAUTY</p>
                <h1>Kategorie usług</h1>
            </div>
            <form action="<?= e(BASE_URL) ?>/wylogowanie.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <button type="submit">Wyloguj się</button>
            </form>
        </div>

        <?php if ($komunikat !== ''): ?>
            <p class="kategorie-ok" role="status"><?= e($komunikat) ?></p>
        <?php endif; ?>
        <?php if ($blad !== ''): ?>
            <p class="kategorie-blad" role="alert"><?= e($blad) ?></p>
        <?php endif; ?>

        <section class="karta" aria-labelledby="tytul-formularza">
            <h2 id="tytul-formularza"><?= $edycja ? 'Edytuj kategorię' : 'Dodaj kategorię' ?></h2>
            <form class="formularz" action="<?= e(BASE_URL) ?>/admin/kategorie.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="akcja" value="<?= $edycja ? 'edytuj' : 'dodaj' ?>">
                <input type="hidden" name="id" value="<?= (int) $formularz['id'] ?>">
                <label>Nazwa (wymagana, maks. 150 znaków)
                    <input type="text" name="nazwa" maxlength="150" required value="<?= e($formularz['nazwa']) ?>">
                </label>
                <label>Opis (opcjonalny)
                    <textarea name="opis" rows="4"><?= e($formularz['opis'] ?? '') ?></textarea>
                </label>
                <label class="wybor">
                    <input type="checkbox" name="aktywny" value="1" <?= (int) $formularz['aktywny'] === 1 ? 'checked' : '' ?>>
                    Kategoria aktywna
                </label>
                <div class="akcje">
                    <button type="submit"><?= $edycja ? 'Zapisz zmiany' : 'Dodaj kategorię' ?></button>
                    <?php if ($edycja): ?>
                        <a class="link" href="<?= e(BASE_URL) ?>/admin/kategorie.php">Anuluj edycję</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="karta" aria-labelledby="tytul-listy">
            <h2 id="tytul-listy">Lista kategorii</h2>
            <div class="tabela">
                <table>
                    <thead><tr><th scope="col">Nazwa</th><th scope="col">Opis</th><th scope="col">Aktywność</th><th scope="col">Działania</th></tr></thead>
                    <tbody>
                    <?php foreach ($kategorie as $kategoria): ?>
                        <tr>
                            <td class="nazwa"><?= e($kategoria['nazwa']) ?></td>
                            <td class="opis"><?= e($kategoria['opis'] ?? '—') ?></td>
                            <td><?= (int) $kategoria['aktywny'] === 1 ? 'Aktywna' : 'Nieaktywna' ?></td>
                            <td>
                                <div class="akcje">
                                    <a class="link" href="<?= e(BASE_URL) ?>/admin/kategorie.php?edytuj=<?= (int) $kategoria['id'] ?>">Edytuj</a>
                                    <form action="<?= e(BASE_URL) ?>/admin/kategorie.php" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="akcja" value="aktywnosc">
                                        <input type="hidden" name="id" value="<?= (int) $kategoria['id'] ?>">
                                        <input type="hidden" name="stan" value="<?= (int) $kategoria['aktywny'] === 1 ? '0' : '1' ?>">
                                        <button type="submit"><?= (int) $kategoria['aktywny'] === 1 ? 'Wyłącz' : 'Włącz' ?></button>
                                    </form>
                                    <form action="<?= e(BASE_URL) ?>/admin/kategorie.php" method="post" onsubmit="return confirm('Czy na pewno usunąć tę kategorię?');">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="akcja" value="usun">
                                        <input type="hidden" name="id" value="<?= (int) $kategoria['id'] ?>">
                                        <button type="submit">Usuń</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$kategorie): ?>
                        <tr><td colspan="4">Brak kategorii do wyświetlenia.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
