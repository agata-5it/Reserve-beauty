<?php

// Wspólne logowanie, połączenie z bazą i ochrona administratora.
require_once __DIR__ . '/../includes/auth.php';
$uzytkownik = wymagajRoli('admin');

function uslugiTekst(array $dane, string $klucz): string
{
    $wartosc = $dane[$klucz] ?? '';
    return is_string($wartosc) ? trim($wartosc) : '';
}

function uslugiId(string $wartosc)
{
    return filter_var($wartosc, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
}

$blad = '';
$komunikat = $_SESSION['uslugi_komunikat'] ?? '';
unset($_SESSION['uslugi_komunikat']);
$formularz = [
    'id_uslugi' => 0, 'id_salonu' => '', 'id_kategoria' => '',
    'nazwa' => '', 'opis' => '', 'czas_trwania' => '', 'cena' => '', 'aktywnosc' => 1,
];

// 1. Dodawanie, edycja i zmiana aktywności odbywają się przez POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akcja = uslugiTekst($_POST, 'akcja');
    $id = uslugiId(uslugiTekst($_POST, 'id_uslugi'));

    if (!sprawdzCsrf()) {
        $blad = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
    } elseif (!in_array($akcja, ['dodaj', 'edytuj', 'aktywnosc'], true)) {
        $blad = 'Nieprawidłowa operacja.';
    } elseif ($akcja !== 'dodaj' && $id === false) {
        $blad = 'Nieprawidłowy numer usługi.';
    } else {
        if ($akcja === 'dodaj' || $akcja === 'edytuj') {
            foreach (['id_salonu', 'id_kategoria', 'nazwa', 'opis', 'czas_trwania', 'cena'] as $pole) {
                $formularz[$pole] = uslugiTekst($_POST, $pole);
            }
            $formularz['id_uslugi'] = $akcja === 'edytuj' ? $id : 0;
            $formularz['aktywnosc'] = uslugiTekst($_POST, 'aktywnosc') === '1' ? 1 : 0;
            $salonId = uslugiId($formularz['id_salonu']);
            $kategoriaId = uslugiId($formularz['id_kategoria']);
            $minuty = uslugiId($formularz['czas_trwania']);
            $cena = str_replace(',', '.', $formularz['cena']);

            // Cena jest tekstem dziesiętnym: bez zaokrąglania przez float.
            // DECIMAL(10,2) pozwala na maksymalnie 99 999 999,99 zł.
            if ($salonId === false || $kategoriaId === false) {
                $blad = 'Wybierz salon i kategorię.';
            } elseif (preg_match('//u', $formularz['nazwa']) !== 1 || preg_match('//u', $formularz['opis']) !== 1) {
                $blad = 'Nazwa lub opis zawiera nieprawidłowe znaki.';
            } elseif (preg_match('/^[\s\p{Z}]*$/u', $formularz['nazwa'])) {
                $blad = 'Podaj nazwę usługi.';
            } elseif (preg_match_all('/./us', $formularz['nazwa']) > 150) {
                $blad = 'Nazwa może mieć maksymalnie 150 znaków.';
            } elseif (strlen($formularz['opis']) > 65535) {
                $blad = 'Opis jest za długi. Skróć go.';
            } elseif ($minuty === false) {
                $blad = 'Czas trwania musi być dodatnią liczbą całkowitą minut (maks. 2147483647).';
            } elseif (!preg_match('/^[0-9]{1,8}(\.[0-9]{1,2})?$/D', $cena)) {
                $blad = 'Podaj cenę od 0 do 99999999,99, z maksymalnie dwoma miejscami po przecinku.';
            }
        }

        if ($blad === '') {
            try {
                $pdo->beginTransaction();
                $staraUsluga = null;
                if ($akcja !== 'dodaj') {
                    $zapytanie = $pdo->prepare('SELECT * FROM uslugi WHERE id_uslugi = ? FOR UPDATE');
                    $zapytanie->execute([$id]);
                    $staraUsluga = $zapytanie->fetch();
                    if (!$staraUsluga) {
                        throw new RuntimeException('Ta usługa już nie istnieje.');
                    }
                }

                if ($akcja === 'aktywnosc') {
                    $stan = uslugiTekst($_POST, 'stan');
                    if (!in_array($stan, ['0', '1'], true)) {
                        throw new RuntimeException('Nieprawidłowa aktywność usługi.');
                    }
                    $zapytanie = $pdo->prepare('UPDATE uslugi SET aktywnosc = ? WHERE id_uslugi = ?');
                    $zapytanie->execute([(int) $stan, $id]);
                    $sukces = $stan === '1' ? 'Włączono usługę.' : 'Wyłączono usługę. Dotychczasowe rezerwacje zostały zachowane.';
                } else {
                    // Sprawdzenie ID w bazie jest potrzebne także przy listach rozwijanych.
                    $zapytanie = $pdo->prepare('SELECT id_salonu FROM salony WHERE id_salonu = ? LOCK IN SHARE MODE');
                    $zapytanie->execute([$salonId]);
                    if (!$zapytanie->fetch()) {
                        throw new RuntimeException('Wybrany salon nie istnieje.');
                    }
                    $zapytanie = $pdo->prepare('SELECT id FROM kategorie_uslug WHERE id = ? LOCK IN SHARE MODE');
                    $zapytanie->execute([$kategoriaId]);
                    if (!$zapytanie->fetch()) {
                        throw new RuntimeException('Wybrana kategoria nie istnieje.');
                    }

                    // Przeniesienie powiązanej usługi zmieniłoby salon jej historii
                    // albo pozostawiło przypisanych pracowników z innego salonu.
                    if ($staraUsluga && (int) $staraUsluga['id_salonu'] !== $salonId) {
                        $zapytanie = $pdo->prepare('SELECT nr_rezerwacji FROM rezerwacje WHERE id_uslugi = ? LIMIT 1 FOR UPDATE');
                        $zapytanie->execute([$id]);
                        if ($zapytanie->fetch()) {
                            throw new RuntimeException('Usługa ma rezerwacje. Nie można przenieść jej do innego salonu; utwórz tam nową usługę.');
                        }
                        $zapytanie = $pdo->prepare('SELECT id_pracownika FROM pracownicy_uslugi WHERE id_uslugi = ? LIMIT 1 FOR UPDATE');
                        $zapytanie->execute([$id]);
                        if ($zapytanie->fetch()) {
                            throw new RuntimeException('Usługa ma przypisanych pracowników. Nie można zmienić jej salonu.');
                        }
                    }

                    $dane = [$salonId, $kategoriaId, $formularz['nazwa'],
                        $formularz['opis'] === '' ? null : $formularz['opis'],
                        $minuty, $cena, $formularz['aktywnosc']];
                    if ($akcja === 'dodaj') {
                        $zapytanie = $pdo->prepare(
                            'INSERT INTO uslugi (id_salonu, id_kategoria, nazwa, opis, czas_trwania, cena, aktywnosc)
                             VALUES (?, ?, ?, ?, ?, ?, ?)'
                        );
                        $sukces = 'Dodano usługę.';
                    } else {
                        $zapytanie = $pdo->prepare(
                            'UPDATE uslugi SET id_salonu = ?, id_kategoria = ?, nazwa = ?, opis = ?,
                             czas_trwania = ?, cena = ?, aktywnosc = ? WHERE id_uslugi = ?'
                        );
                        $dane[] = $id;
                        $sukces = 'Zapisano zmiany usługi.';
                    }
                    $zapytanie->execute($dane);
                    // Nie aktualizujemy tabeli rezerwacje: ma własną cenę i daty wizyty.
                }
                $pdo->commit();
                $_SESSION['uslugi_komunikat'] = $sukces;
                przekieruj('/admin/uslugi.php');
            } catch (PDOException $wyjatek) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Uslugi: SQLSTATE ' . $wyjatek->getCode());
                $blad = 'Nie udało się zapisać zmian. Spróbuj ponownie.';
            } catch (RuntimeException $wyjatek) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $blad = $wyjatek->getMessage();
            }
        }
    }
}

// 2. Listy wyboru, edycja i filtrowanie. GET służy tylko do odczytu.
$salony = [];
$kategorie = [];
$uslugi = [];
$filtrSalon = uslugiTekst($_GET, 'salon');
$szukaj = uslugiTekst($_GET, 'szukaj');
$odczytOK = false;
try {
    $salony = $pdo->query('SELECT id_salonu, nazwa, miasto, aktywnosc FROM salony ORDER BY nazwa')->fetchAll();
    $kategorie = $pdo->query('SELECT id, nazwa, aktywny FROM kategorie_uslug ORDER BY nazwa')->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edytuj'])) {
        $idEdycji = uslugiId(uslugiTekst($_GET, 'edytuj'));
        if ($idEdycji === false) {
            $blad = 'Nieprawidłowy numer usługi.';
        } else {
            $zapytanie = $pdo->prepare('SELECT * FROM uslugi WHERE id_uslugi = ?');
            $zapytanie->execute([$idEdycji]);
            $wiersz = $zapytanie->fetch();
            if ($wiersz) {
                $formularz = $wiersz;
            } else {
                $blad = 'Nie znaleziono usługi.';
            }
        }
    }

    $sql = 'SELECT u.*, s.nazwa AS salon_nazwa, s.miasto, k.nazwa AS kategoria_nazwa
            FROM uslugi u
            JOIN salony s ON s.id_salonu = u.id_salonu
            JOIN kategorie_uslug k ON k.id = u.id_kategoria
            WHERE 1 = 1';
    $parametry = [];
    if ($filtrSalon !== '') {
        $filtrId = uslugiId($filtrSalon);
        if ($filtrId === false) {
            throw new RuntimeException('Nieprawidłowy filtr salonu. Wyczyść filtry.');
        }
        $sql .= ' AND u.id_salonu = ?';
        $parametry[] = $filtrId;
    }
    if ($szukaj !== '') {
        if (preg_match('//u', $szukaj) !== 1 || preg_match_all('/./us', $szukaj) > 150) {
            throw new RuntimeException('Wyszukiwana nazwa może mieć maksymalnie 150 poprawnych znaków.');
        }
        // Szukamy zwykłego tekstu; % i _ nie są symbolami wieloznacznymi.
        $sql .= " AND u.nazwa LIKE ? ESCAPE '!'";
        $parametry[] = '%' . strtr($szukaj, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
    $sql .= ' ORDER BY s.nazwa, u.nazwa, u.id_uslugi';
    $zapytanie = $pdo->prepare($sql);
    $zapytanie->execute($parametry);
    $uslugi = $zapytanie->fetchAll();
    $odczytOK = true;
} catch (PDOException $wyjatek) {
    error_log('Odczyt uslug: SQLSTATE ' . $wyjatek->getCode());
    $blad = 'Nie udało się pobrać danych. Spróbuj ponownie później.';
} catch (RuntimeException $wyjatek) {
    $blad = $wyjatek->getMessage();
}

$edycja = (int) $formularz['id_uslugi'] > 0;
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
    <title>Usługi — Reserve Beauty</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin2.css">
</head>
<body class="strona-konta">
    <nav class="admin-menu" aria-label="Menu administratora">
        <ul class="admin-menu_lista">
            <?php foreach ($menu as $plik => $etykieta): ?>
                <li>
                    <a href="<?= e(BASE_URL . '/admin/' . $plik) ?>" <?= $plik === 'uslugi.php' ? 'aria-current="page"' : '' ?>><?= e($etykieta) ?></a>
                </li>
            <?php endforeach; ?>
            <li><a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a></li>
        </ul>
    </nav>
    <main>
        <div class="pole">
            <div>
                <p class="marka">RESERVE BEAUTY</p>
                <h1>Usługi</h1>
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
            <h2 id="tytul-formularza"><?= $edycja ? 'Edytuj usługę' : 'Dodaj usługę' ?></h2>
            <?php if (!$salony || !$kategorie): ?>
                <p>Aby dodać usługę, najpierw dodaj przynajmniej jeden salon i jedną kategorię.</p>
            <?php endif; ?>
            <form class="formularz" action="<?= e(BASE_URL) ?>/admin/uslugi.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="akcja" value="<?= $edycja ? 'edytuj' : 'dodaj' ?>">
                <input type="hidden" name="id_uslugi" value="<?= (int) $formularz['id_uslugi'] ?>">
                <label>Salon
                    <select name="id_salonu" required>
                        <option value="">Wybierz salon</option>
                        <?php foreach ($salony as $salon): ?>
                            <option value="<?= (int) $salon['id_salonu'] ?>" <?= (string) $formularz['id_salonu'] === (string) $salon['id_salonu'] ? 'selected' : '' ?>><?= e($salon['nazwa'] . ' — ' . $salon['miasto']) ?><?= (int) $salon['aktywnosc'] === 1 ? '' : ' (nieaktywny)' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Kategoria
                    <select name="id_kategoria" required>
                        <option value="">Wybierz kategorię</option>
                        <?php foreach ($kategorie as $kategoria): ?>
                            <option value="<?= (int) $kategoria['id'] ?>" <?= (string) $formularz['id_kategoria'] === (string) $kategoria['id'] ? 'selected' : '' ?>><?= e($kategoria['nazwa']) ?><?= (int) $kategoria['aktywny'] === 1 ? '' : ' (nieaktywna)' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Nazwa (maks. 150 znaków)
                    <input type="text" name="nazwa" maxlength="150" required value="<?= e($formularz['nazwa']) ?>">
                </label>
                <label>Opis (opcjonalny)
                    <textarea name="opis" rows="4"><?= e($formularz['opis'] ?? '') ?></textarea>
                </label>
                <label>Czas trwania w minutach
                    <input type="number" name="czas_trwania" min="1" max="2147483647" step="1" required value="<?= e((string) $formularz['czas_trwania']) ?>">
                </label>
                <label>Cena w zł (np. 120,50)
                    <input type="text" name="cena" inputmode="decimal" maxlength="11" required value="<?= e((string) $formularz['cena']) ?>">
                </label>
                <label class="wybor">
                    <input type="checkbox" name="aktywnosc" value="1" <?= (int) $formularz['aktywnosc'] === 1 ? 'checked' : '' ?>>
                    Usługa aktywna
                </label>
                <div class="akcje">
                    <button type="submit" <?= !$salony || !$kategorie ? 'disabled' : '' ?>><?= $edycja ? 'Zapisz zmiany' : 'Dodaj usługę' ?></button>
                    <?php if ($edycja): ?>
                        <a class="link" href="<?= e(BASE_URL) ?>/admin/uslugi.php">Anuluj edycję</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="karta" aria-labelledby="tytul-listy">
            <h2 id="tytul-listy">Lista usług</h2>
            <form class="formularz" action="<?= e(BASE_URL) ?>/admin/uslugi.php" method="get">
                <label>Filtr salonu
                    <select name="salon">
                        <option value="">Wszystkie salony</option>
                        <?php foreach ($salony as $salon): ?>
                            <option value="<?= (int) $salon['id_salonu'] ?>" <?= $filtrSalon === (string) $salon['id_salonu'] ? 'selected' : '' ?>><?= e($salon['nazwa'] . ' — ' . $salon['miasto']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Szukaj po nazwie usługi
                    <input type="search" name="szukaj" maxlength="150" value="<?= e($szukaj) ?>">
                </label>
                <div class="akcje">
                    <button type="submit">Szukaj</button>
                    <a class="link" href="<?= e(BASE_URL) ?>/admin/uslugi.php">Wyczyść filtry</a>
                </div>
            </form>
            <p>Zmiana ceny lub czasu usługi nie zmienia już zapisanych wizyt. Zamiast usuwać usługę, możesz ją wyłączyć.</p>
            <div class="tabela">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Usługa</th><th scope="col">Salon</th><th scope="col">Kategoria</th>
                            <th scope="col">Czas</th><th scope="col">Cena</th><th scope="col">Aktywność</th><th scope="col">Działania</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($uslugi as $usluga): ?>
                            <tr>
                                <td class="tekst"><strong><?= e($usluga['nazwa']) ?></strong><?php if (!empty($usluga['opis'])): ?><br><?= e($usluga['opis']) ?><?php endif; ?></td>
                                <td class="tekst"><?= e($usluga['salon_nazwa'] . ' — ' . $usluga['miasto']) ?></td>
                                <td class="tekst"><?= e($usluga['kategoria_nazwa']) ?></td>
                                <td><?= (int) $usluga['czas_trwania'] ?> min</td>
                                <td><?= e(number_format((float) $usluga['cena'], 2, ',', ' ')) ?> zł</td>
                                <td><?= (int) $usluga['aktywnosc'] === 1 ? 'Aktywna' : 'Nieaktywna' ?></td>
                                <td>
                                    <div class="akcje">
                                        <a class="link" href="<?= e(BASE_URL) ?>/admin/uslugi.php?edytuj=<?= (int) $usluga['id_uslugi'] ?>">Edytuj</a>
                                        <form action="<?= e(BASE_URL) ?>/admin/uslugi.php" method="post">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="akcja" value="aktywnosc">
                                            <input type="hidden" name="id_uslugi" value="<?= (int) $usluga['id_uslugi'] ?>">
                                            <input type="hidden" name="stan" value="<?= (int) $usluga['aktywnosc'] === 1 ? '0' : '1' ?>">
                                            <button type="submit"><?= (int) $usluga['aktywnosc'] === 1 ? 'Wyłącz' : 'Włącz' ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$uslugi): ?>
                            <tr><td colspan="7"><?= $odczytOK ? 'Brak usług spełniających wybrane warunki.' : 'Lista usług jest niedostępna.' ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>