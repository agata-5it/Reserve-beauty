<?php

require_once __DIR__ . '/../includes/auth.php';
$administrator = wymagajRoli('admin');

// Przycisk na liście pracowników przekazuje ID w adresie strony.
$suroweId = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['id_pracownika'] ?? '') : ($_GET['id_pracownika'] ?? '');
$id = is_string($suroweId)
    ? filter_var($suroweId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) : false;

$blad = '';
$komunikat = '';
$pracownik = null;
$uslugi = [];
$zaznaczone = [];
$wyslane = null;
$gotowe = false;

if ($id === false) {
    http_response_code(400);
    $blad = 'Wybierz pracownika przyciskiem „Usługi pracownika” na liście pracowników.';
}

// 1. Zapis zaznaczonych usług.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id !== false) {
    if (!sprawdzCsrf()) {
        $blad = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
    } else {
        $wybrane = $_POST['uslugi'] ?? [];
        $wyslane = [];
        if (!is_array($wybrane)) {
            $blad = 'Nieprawidłowa lista usług.';
        } else {
            foreach ($wybrane as $wartosc) {
                $idUslugi = is_string($wartosc)
                    ? filter_var($wartosc, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) : false;
                if ($idUslugi === false) {
                    $blad = 'Lista zawiera nieprawidłowy numer usługi.';
                    break;
                }
                $wyslane[] = $idUslugi;
            }
            // Powtórzone ID w żądaniu nie tworzą powtórzonych przypisań.
            $wyslane = array_values(array_unique($wyslane));
        }

        if ($blad === '') {
            try {
                $pdo->beginTransaction();
                // Blokada pracownika kolejkuje równoczesne zapisy jego przypisań.
                $zapytanie = $pdo->prepare('SELECT id_pracownika, id_salonu FROM pracownicy WHERE id_pracownika = ? FOR UPDATE');
                $zapytanie->execute([$id]);
                $osoba = $zapytanie->fetch();
                if (!$osoba) {
                    throw new RuntimeException('Ten pracownik już nie istnieje.');
                }

                // Sprawdzamy salon każdej usługi w PHP, nie ufamy samym checkboxom.
                $zapytanie = $pdo->prepare('SELECT id_uslugi FROM uslugi WHERE id_salonu = ? LOCK IN SHARE MODE');
                $zapytanie->execute([$osoba['id_salonu']]);
                $dozwolone = array_map('intval', $zapytanie->fetchAll(PDO::FETCH_COLUMN));
                foreach ($wyslane as $idUslugi) {
                    if (!in_array($idUslugi, $dozwolone, true)) {
                        throw new RuntimeException('Wybrana usługa nie istnieje lub należy do innego salonu.');
                    }
                }

                $zapytanie = $pdo->prepare('SELECT id_uslugi FROM pracownicy_uslugi WHERE id_pracownika = ? FOR UPDATE');
                $zapytanie->execute([$id]);
                $obecne = array_map('intval', $zapytanie->fetchAll(PDO::FETCH_COLUMN));
                if (array_diff($obecne, $dozwolone)) {
                    throw new RuntimeException('W bazie są przypisania usług spoza salonu pracownika. Najpierw popraw te powiązania.');
                }
                $doUsuniecia = array_diff($obecne, $wyslane);
                $doDodania = array_diff($wyslane, $obecne);

                // Sprawdzamy wszystkie usuwane przypisania PRZED pierwszą zmianą.
                // Blokują wizyty oczekujące i potwierdzone, również już rozpoczęte.
                $teraz = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d H:i:s');
                $sprawdz = $pdo->prepare(
                    "SELECT nr_rezerwacji FROM rezerwacje
                     WHERE id_pracownika = ? AND id_uslugi = ?
                       AND status IN ('oczekujaca', 'potwierdzona')
                       AND data_zakonczenia > ?
                     LIMIT 1 FOR UPDATE"
                );
                foreach ($doUsuniecia as $idUslugi) {
                    $sprawdz->execute([$id, $idUslugi, $teraz]);
                    $rezerwacja = $sprawdz->fetch();
                    if ($rezerwacja) {
                        throw new RuntimeException(
                            'Nie można usunąć przypisania: istnieje nadchodząca lub trwająca wizyta nr '
                            . $rezerwacja['nr_rezerwacji'] . '. Najpierw rozwiąż tę rezerwację. Nie zapisano żadnych zmian.'
                        );
                    }
                }

                // Zmieniamy wyłącznie tabelę powiązań, nigdy historię rezerwacji.
                $usun = $pdo->prepare('DELETE FROM pracownicy_uslugi WHERE id_pracownika = ? AND id_uslugi = ?');
                foreach ($doUsuniecia as $idUslugi) {
                    $usun->execute([$id, $idUslugi]);
                }
                $dodaj = $pdo->prepare('INSERT INTO pracownicy_uslugi (id_pracownika, id_uslugi) VALUES (?, ?)');
                foreach ($doDodania as $idUslugi) {
                    $dodaj->execute([$id, $idUslugi]);
                }
                $pdo->commit();
                $_SESSION['przypisania_sukces'] = $id;
                przekieruj('/admin/pracownik-uslugi.php?id_pracownika=' . $id);
            } catch (PDOException $wyjatek) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Przypisania uslug: SQLSTATE ' . $wyjatek->getCode());
                $blad = 'Nie udało się zapisać przypisań. Odśwież stronę i spróbuj ponownie.';
            } catch (RuntimeException $wyjatek) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $blad = $wyjatek->getMessage();
            }
        }
    }
}

// 2. Dane pracownika, jego salon i usługi dostępne w tym salonie.
if ($id !== false) {
    try {
        $zapytanie = $pdo->prepare(
            'SELECT p.id_pracownika, p.id_salonu, u.imie, u.nazwisko, s.nazwa AS salon, s.miasto
             FROM pracownicy p
             JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika
             JOIN salony s ON s.id_salonu = p.id_salonu
             WHERE p.id_pracownika = ?'
        );
        $zapytanie->execute([$id]);
        $pracownik = $zapytanie->fetch();
        if (!$pracownik) {
            http_response_code(404);
            $blad = 'Nie znaleziono pracownika.';
        } else {
            $zapytanie = $pdo->prepare('SELECT id_uslugi, nazwa, czas_trwania, cena, aktywnosc FROM uslugi WHERE id_salonu = ? ORDER BY nazwa');
            $zapytanie->execute([$pracownik['id_salonu']]);
            $uslugi = $zapytanie->fetchAll();
            $zapytanie = $pdo->prepare('SELECT id_uslugi FROM pracownicy_uslugi WHERE id_pracownika = ?');
            $zapytanie->execute([$id]);
            $zapisane = array_map('intval', $zapytanie->fetchAll(PDO::FETCH_COLUMN));
            $zaznaczone = $wyslane !== null ? $wyslane : $zapisane;
            $widoczne = array_map('intval', array_column($uslugi, 'id_uslugi'));
            // Nie usuwamy po cichu starszych, błędnych powiązań z innym salonem.
            if (array_diff($zapisane, $widoczne)) {
                $blad = 'W bazie są przypisania usług spoza salonu tego pracownika. Najpierw popraw te powiązania; zapis został zablokowany.';
            } else {
                $gotowe = true;
            }
        }
    } catch (PDOException $wyjatek) {
        error_log('Odczyt przypisan: SQLSTATE ' . $wyjatek->getCode());
        $blad = 'Nie udało się pobrać danych. Spróbuj ponownie później.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $gotowe && ($_SESSION['przypisania_sukces'] ?? null) === $id) {
    $komunikat = 'Zapisano usługi pracownika.';
    unset($_SESSION['przypisania_sukces']);
}
$menu = [
    'index.php' => 'Podsumowanie', 'kategorie.php' => 'Kategorie', 'salony.php' => 'Salony',
    'uslugi.php' => 'Usługi', 'pracownicy.php' => 'Pracownicy', 'dostepnosc.php' => 'Dostępność',
    'urlopy.php' => 'Urlopy', 'rezerwacje.php' => 'Rezerwacje',
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usługi pracownika — Reserve Beauty</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin2.css">
    <style>
        #przypisania-formularz { display: flex; flex-direction: column; align-items: stretch; justify-content: flex-start; gap: 12px; width: 100%; max-width: 750px; box-sizing: border-box; }
        #przypisania-formularz .usluga-wybor { display: flex; align-items: flex-start; gap: 12px; padding: 14px; border: 1px solid #e8dcdf; border-radius: 10px; overflow-wrap: anywhere; }
        #przypisania-formularz input[type="checkbox"] { flex: 0 0 auto; width: 18px; height: 18px; margin: 4px 0 0; }
        #przypisania-formularz button { align-self: flex-start; }
        .szczegoly-uslugi { display: block; color: #65575e; font-size: 14px; }
    </style>
</head>
<body class="strona-konta">
    <nav class="admin-menu" aria-label="Menu administratora">
        <ul class="admin-menu_lista">
            <?php foreach ($menu as $plik => $etykieta): ?>
                <li><a href="<?= e(BASE_URL . '/admin/' . $plik) ?>" <?= $plik === 'pracownicy.php' ? 'aria-current="location"' : '' ?>><?= e($etykieta) ?></a></li>
            <?php endforeach; ?>
            <li><a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a></li>
        </ul>
    </nav>
    <main>
        <div class="pole">
            <div>
                <p class="marka">RESERVE BEAUTY</p>
                <h1>Usługi pracownika</h1>
            </div>
            <form action="<?= e(BASE_URL) ?>/wylogowanie.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <button type="submit">Wyloguj się</button>
            </form>
        </div>
        <section class="karta" aria-labelledby="wybor-tytul">
            <a class="link" href="<?= e(BASE_URL) ?>/admin/pracownicy.php">← Wróć do pracowników</a>
            <?php if ($komunikat !== ''): ?>
                <p class="ok" role="status"><?= e($komunikat) ?></p>
            <?php endif; ?>
            <?php if ($blad !== ''): ?>
                <p class="blad" role="alert"><?= e($blad) ?></p>
            <?php endif; ?>
            <h2 id="wybor-tytul">Wybierz wykonywane usługi</h2>
            <?php if ($pracownik): ?>
                <p><strong><?= e($pracownik['imie'] . ' ' . $pracownik['nazwisko']) ?></strong><br>
                    Salon: <?= e($pracownik['salon'] . ' — ' . $pracownik['miasto']) ?></p>
            <?php endif; ?>
            <?php if ($gotowe): ?>
                <p>Zaznacz usługi wykonywane przez tę osobę. Odznaczenie usuwa przypisanie, ale zachowuje historię wizyt.</p>
                <form id="przypisania-formularz" action="<?= e(BASE_URL) ?>/admin/pracownik-uslugi.php?id_pracownika=<?= (int) $id ?>" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="id_pracownika" value="<?= (int) $id ?>">
                    <?php foreach ($uslugi as $usluga): ?>
                        <label class="usluga-wybor">
                            <input type="checkbox" name="uslugi[]" value="<?= (int) $usluga['id_uslugi'] ?>" <?= in_array((int) $usluga['id_uslugi'], $zaznaczone, true) ? 'checked' : '' ?>>
                            <span>
                                <strong><?= e($usluga['nazwa']) ?></strong>
                                <span class="szczegoly-uslugi">
                                    <?= (int) $usluga['czas_trwania'] ?> min · <?= e(number_format((float) $usluga['cena'], 2, ',', ' ')) ?> zł
                                    <?= (int) $usluga['aktywnosc'] === 1 ? '' : ' · usługa nieaktywna' ?>
                                </span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                    <?php if (!$uslugi): ?>
                        <p>Ten salon nie ma jeszcze usług. Dodaj je w zakładce Usługi.</p>
                    <?php else: ?>
                        <button type="submit">Zapisz usługi pracownika</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
