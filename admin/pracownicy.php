<?php

require_once __DIR__ . '/../includes/auth.php';
$administrator = wymagajRoli('admin');

// Odczyt tekstu i numerów z formularza. Nie przyjmujemy tablic zamiast tekstu.
function pracownicyTekst(array $dane, string $pole): string
{
    return isset($dane[$pole]) && is_string($dane[$pole]) ? $dane[$pole] : '';
}

function pracownicyId(string $tekst)
{
    return filter_var($tekst, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
}

function pracownicyDlugosc(string $tekst): int
{
    return preg_match('//u', $tekst) === 1 ? preg_match_all('/./us', $tekst) : -1;
}

$blad = '';
$komunikat = $_SESSION['pracownicy_komunikat'] ?? '';
unset($_SESSION['pracownicy_komunikat']);
$tryb = pracownicyTekst($_GET, 'tryb') === 'nowy' ? 'nowy' : 'przypisz';
$formularz = [
    'id_pracownika' => 0, 'id_uzytkownika' => '', 'id_salonu' => '', 'opis' => '',
    'aktywnosc' => 1, 'aktywny' => 1, 'imie' => '', 'nazwisko' => '', 'email' => '', 'telefon' => '',
];

// 1. Zapis danych. Zmiany wymagają uprawnień administratora, POST i CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akcja = pracownicyTekst($_POST, 'akcja');
    $id = pracownicyId(pracownicyTekst($_POST, 'id_pracownika'));
    $tryb = in_array($akcja, ['nowy', 'przypisz', 'edytuj'], true) ? $akcja : 'przypisz';

    if (!sprawdzCsrf()) {
        $blad = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
    } elseif (!in_array($akcja, ['nowy', 'przypisz', 'edytuj', 'pracownik_stan', 'konto_stan'], true)) {
        $blad = 'Nieprawidłowa operacja.';
    } elseif (in_array($akcja, ['edytuj', 'pracownik_stan', 'konto_stan'], true) && $id === false) {
        $blad = 'Nieprawidłowy numer pracownika.';
    } else {
        if (in_array($akcja, ['nowy', 'przypisz', 'edytuj'], true)) {
            foreach (['id_uzytkownika', 'id_salonu', 'opis', 'imie', 'nazwisko', 'email', 'telefon'] as $pole) {
                $formularz[$pole] = trim(pracownicyTekst($_POST, $pole));
            }
            $formularz['id_pracownika'] = $akcja === 'edytuj' ? $id : 0;
            $formularz['aktywnosc'] = pracownicyTekst($_POST, 'aktywnosc') === '1' ? 1 : 0;
            $formularz['aktywny'] = pracownicyTekst($_POST, 'aktywny') === '1' ? 1 : 0;
            $salonId = pracownicyId($formularz['id_salonu']);
            $kontoId = pracownicyId($formularz['id_uzytkownika']);

            if ($salonId === false) {
                $blad = 'Wybierz salon.';
            } elseif ($akcja === 'przypisz' && $kontoId === false) {
                $blad = 'Wybierz konto pracownika.';
            } elseif (pracownicyDlugosc($formularz['opis']) < 0 || strlen($formularz['opis']) > 65535) {
                $blad = 'Opis zawiera nieprawidłowe znaki lub jest za długi.';
            }
        }

        // Takie same limity jak w Waszej rejestracji: 50, 50, 100 i hasło min. 12 znaków.
        if ($akcja === 'nowy' && $blad === '') {
            $formularz['telefon'] = str_replace([' ', '-'], '', $formularz['telefon']);
            $haslo = pracownicyTekst($_POST, 'haslo');
            $powtorzHaslo = pracownicyTekst($_POST, 'powtorz_haslo');
            foreach (['imie' => 'Imię', 'nazwisko' => 'Nazwisko'] as $pole => $etykieta) {
                $dlugosc = pracownicyDlugosc($formularz[$pole]);
                if ($dlugosc < 1 || $dlugosc > 50 || preg_match('/^[\s\p{Z}]*$/u', $formularz[$pole])) {
                    $blad = $etykieta . ' musi mieć od 1 do 50 znaków i nie może zawierać samych spacji.';
                    break;
                }
            }
            if ($blad === '' && (!filter_var($formularz['email'], FILTER_VALIDATE_EMAIL) || strlen($formularz['email']) > 100)) {
                $blad = 'Podaj poprawny adres e-mail (maksymalnie 100 znaków).';
            } elseif ($blad === '' && !preg_match('/^\+?[0-9]{9,15}$/D', $formularz['telefon'])) {
                $blad = 'Telefon musi mieć od 9 do 15 cyfr, opcjonalnie z + na początku.';
            } elseif ($blad === '' && (pracownicyDlugosc($haslo) < 12 || strlen($haslo) > 72 || strpos($haslo, "\0") !== false)) {
                $blad = 'Hasło musi mieć co najmniej 12 znaków i maksymalnie 72 bajty.';
            } elseif ($blad === '' && $haslo !== $powtorzHaslo) {
                $blad = 'Wpisane hasła nie są takie same.';
            }
        }

        if ($blad === '') {
            $blokada = false;
            $sukces = '';
            try {
                // Kolejkujemy równoczesne operacje tego modułu (m.in. kontrolę telefonu).
                $nazwaBlokady = 'rb_prac_' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
                $zapytanie = $pdo->prepare('SELECT GET_LOCK(?, 5)');
                $zapytanie->execute([$nazwaBlokady]);
                $blokada = (int) $zapytanie->fetchColumn() === 1;
                if (!$blokada) {
                    throw new RuntimeException('Trwa inny zapis pracownika. Spróbuj ponownie za chwilę.');
                }
                $pdo->beginTransaction();
                $pracownik = null;

                if (in_array($akcja, ['edytuj', 'pracownik_stan', 'konto_stan'], true)) {
                    $zapytanie = $pdo->prepare('SELECT * FROM pracownicy WHERE id_pracownika = ? FOR UPDATE');
                    $zapytanie->execute([$id]);
                    $pracownik = $zapytanie->fetch();
                    if (!$pracownik) {
                        throw new RuntimeException('Nie znaleziono pracownika.');
                    }
                    $zapytanie = $pdo->prepare('SELECT rola FROM uzytkownicy WHERE id_uzytkownika = ? FOR UPDATE');
                    $zapytanie->execute([$pracownik['id_uzytkownika']]);
                    if ($zapytanie->fetchColumn() !== 'pracownik') {
                        throw new RuntimeException('Powiązane konto nie ma roli pracownika. Sprawdź dane konta.');
                    }
                }

                if ($akcja === 'pracownik_stan' || $akcja === 'konto_stan') {
                    $stan = pracownicyTekst($_POST, 'stan');
                    if (!in_array($stan, ['0', '1'], true)) {
                        throw new RuntimeException('Nieprawidłowy stan aktywności.');
                    }
                    if ($akcja === 'pracownik_stan') {
                        $zapytanie = $pdo->prepare('UPDATE pracownicy SET aktywnosc = ? WHERE id_pracownika = ?');
                        $zapytanie->execute([(int) $stan, $id]);
                        $sukces = $stan === '1' ? 'Włączono pracownika.' : 'Wyłączono pracownika. Dotychczasowe wizyty pozostają w bazie.';
                    } else {
                        $zapytanie = $pdo->prepare('UPDATE uzytkownicy SET aktywny = ? WHERE id_uzytkownika = ?');
                        $zapytanie->execute([(int) $stan, $pracownik['id_uzytkownika']]);
                        $sukces = $stan === '1' ? 'Odblokowano logowanie pracownika.' : 'Zablokowano logowanie pracownika.';
                    }
                } else {
                    $zapytanie = $pdo->prepare('SELECT id_salonu FROM salony WHERE id_salonu = ? LOCK IN SHARE MODE');
                    $zapytanie->execute([$salonId]);
                    if (!$zapytanie->fetch()) {
                        throw new RuntimeException('Wybrany salon nie istnieje.');
                    }

                    if ($akcja === 'przypisz') {
                        $zapytanie = $pdo->prepare('SELECT rola FROM uzytkownicy WHERE id_uzytkownika = ? FOR UPDATE');
                        $zapytanie->execute([$kontoId]);
                        if ($zapytanie->fetchColumn() !== 'pracownik') {
                            throw new RuntimeException('Wybierz istniejące konto z rolą pracownik.');
                        }
                        $zapytanie = $pdo->prepare('SELECT id_pracownika FROM pracownicy WHERE id_uzytkownika = ? FOR UPDATE');
                        $zapytanie->execute([$kontoId]);
                        if ($zapytanie->fetch()) {
                            throw new RuntimeException('To konto jest już przypisane do pracownika.');
                        }
                    } elseif ($akcja === 'nowy') {
                        $zapytanie = $pdo->prepare('SELECT id_uzytkownika FROM uzytkownicy WHERE email = ? LIMIT 1');
                        $zapytanie->execute([$formularz['email']]);
                        if ($zapytanie->fetch()) {
                            throw new RuntimeException('Konto z tym adresem e-mail już istnieje.');
                        }
                        // Ignorujemy spacje, myślniki i początkowy + przy porównywaniu telefonu.
                        $zapytanie = $pdo->prepare("SELECT id_uzytkownika FROM uzytkownicy WHERE REPLACE(REPLACE(REPLACE(telefon, ' ', ''), '-', ''), '+', '') = ? LIMIT 1");
                        $zapytanie->execute([ltrim($formularz['telefon'], '+')]);
                        if ($zapytanie->fetch()) {
                            throw new RuntimeException('Konto z tym numerem telefonu już istnieje.');
                        }
                        $hash = password_hash($haslo, PASSWORD_DEFAULT);
                        if ($hash === false) {
                            throw new RuntimeException('Nie udało się zabezpieczyć hasła. Spróbuj ponownie.');
                        }
                        $zapytanie = $pdo->prepare(
                            "INSERT INTO uzytkownicy (imie, nazwisko, email, telefon, haslo, rola, aktywny)
                             VALUES (?, ?, ?, ?, ?, 'pracownik', ?)"
                        );
                        $zapytanie->execute([$formularz['imie'], $formularz['nazwisko'], $formularz['email'],
                            $formularz['telefon'], $hash, $formularz['aktywny']]);
                        $kontoId = (int) $pdo->lastInsertId();
                    }

                    if ($akcja === 'edytuj') {
                        if ((int) $pracownik['id_salonu'] !== $salonId) {
                            // Nie przenosimy pracownika z historią, urlopami ani przypisanymi usługami.
                            $zapytanie = $pdo->prepare('SELECT nr_rezerwacji FROM rezerwacje WHERE id_pracownika = ? LIMIT 1 FOR UPDATE');
                            $zapytanie->execute([$id]);
                            if ($zapytanie->fetch()) {
                                throw new RuntimeException('Pracownik ma rezerwacje. Zmiana salonu jest zablokowana.');
                            }
                            $zapytanie = $pdo->prepare('SELECT id_urlopu FROM urlopy WHERE id_pracownika = ? LIMIT 1 FOR UPDATE');
                            $zapytanie->execute([$id]);
                            if ($zapytanie->fetch()) {
                                throw new RuntimeException('Pracownik ma wpisy urlopowe. Zmiana salonu jest zablokowana.');
                            }
                            $zapytanie = $pdo->prepare('SELECT id_uslugi FROM pracownicy_uslugi WHERE id_pracownika = ? LIMIT 1 FOR UPDATE');
                            $zapytanie->execute([$id]);
                            if ($zapytanie->fetch()) {
                                throw new RuntimeException('Pracownik ma przypisane usługi. Zmiana salonu jest zablokowana.');
                            }
                        }
                        $zapytanie = $pdo->prepare('UPDATE pracownicy SET id_salonu = ?, opis = ?, aktywnosc = ? WHERE id_pracownika = ?');
                        $zapytanie->execute([$salonId, $formularz['opis'] === '' ? null : $formularz['opis'], $formularz['aktywnosc'], $id]);
                        $sukces = 'Zapisano dane zawodowe pracownika.';
                    } else {
                        // Konto i profil powstają w tej samej transakcji: oba albo żaden.
                        $zapytanie = $pdo->prepare('INSERT INTO pracownicy (id_uzytkownika, id_salonu, opis, aktywnosc) VALUES (?, ?, ?, ?)');
                        $zapytanie->execute([$kontoId, $salonId, $formularz['opis'] === '' ? null : $formularz['opis'], $formularz['aktywnosc']]);
                        $sukces = $akcja === 'nowy' ? 'Utworzono konto i profil pracownika.' : 'Przypisano konto pracownika do salonu.';
                    }
                }
                $pdo->commit();
            } catch (PDOException $wyjatek) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Pracownicy: SQLSTATE ' . $wyjatek->getCode());
                $blad = (int) ($wyjatek->errorInfo[1] ?? 0) === 1062
                    ? 'Podany e-mail lub przypisanie konta jest już zajęte.'
                    : 'Nie udało się zapisać zmian. Spróbuj ponownie.';
            } catch (RuntimeException $wyjatek) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $blad = $wyjatek->getMessage();
            } finally {
                if ($blokada) {
                    try {
                        $zapytanie = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                        $zapytanie->execute([$nazwaBlokady]);
                    } catch (PDOException $wyjatek) {
                        error_log('Zwolnienie blokady pracownikow: SQLSTATE ' . $wyjatek->getCode());
                    }
                }
            }
            if ($blad === '') {
                $_SESSION['pracownicy_komunikat'] = $sukces;
                przekieruj('/admin/pracownicy.php');
            }
        }
    }
}

// 2. Odczyt list. Imię i nazwisko zawsze pochodzą z tabeli uzytkownicy.
$pracownicy = [];
$salony = [];
$konta = [];
$odczytOK = false;
try {
    $salony = $pdo->query('SELECT id_salonu, nazwa, miasto, aktywnosc FROM salony ORDER BY nazwa')->fetchAll();
    $konta = $pdo->query(
        "SELECT u.id_uzytkownika, u.imie, u.nazwisko, u.email, u.aktywny
         FROM uzytkownicy u
         WHERE u.rola = 'pracownik'
           AND NOT EXISTS (SELECT 1 FROM pracownicy p WHERE p.id_uzytkownika = u.id_uzytkownika)
         ORDER BY u.nazwisko, u.imie"
    )->fetchAll();
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edytuj'])) {
        $idEdycji = pracownicyId(pracownicyTekst($_GET, 'edytuj'));
        if ($idEdycji === false) {
            throw new RuntimeException('Nieprawidłowy numer pracownika.');
        }
        $zapytanie = $pdo->prepare('SELECT p.*, u.imie, u.nazwisko, u.email FROM pracownicy p JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika WHERE p.id_pracownika = ?');
        $zapytanie->execute([$idEdycji]);
        $wiersz = $zapytanie->fetch();
        if (!$wiersz) {
            throw new RuntimeException('Nie znaleziono pracownika.');
        }
        $formularz = array_merge($formularz, $wiersz);
        $tryb = 'edytuj';
    }
    $pracownicy = $pdo->query(
        'SELECT p.id_pracownika, p.opis, p.aktywnosc, u.imie, u.nazwisko, u.email, u.telefon, u.aktywny,
                s.nazwa AS salon_nazwa, s.miasto
         FROM pracownicy p
         JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika
         JOIN salony s ON s.id_salonu = p.id_salonu
         ORDER BY u.nazwisko, u.imie, p.id_pracownika'
    )->fetchAll();
    $odczytOK = true;
} catch (PDOException $wyjatek) {
    error_log('Odczyt pracownikow: SQLSTATE ' . $wyjatek->getCode());
    $blad = 'Nie udało się pobrać danych. Spróbuj ponownie później.';
} catch (RuntimeException $wyjatek) {
    $blad = $wyjatek->getMessage();
}

$menu = [
    'index.php' => 'Podsumowanie', 'kategorie.php' => 'Kategorie', 'salony.php' => 'Salony',
    'uslugi.php' => 'Usługi', 'pracownicy.php' => 'Pracownicy', 'dostepnosc.php' => 'Dostępność',
    'urlopy.php' => 'Urlopy', 'rezerwacje.php' => 'Rezerwacje',
];
$tytuly = ['przypisz' => 'Przypisz istniejące konto', 'nowy' => 'Utwórz konto i profil pracownika', 'edytuj' => 'Edytuj dane zawodowe pracownika'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pracownicy — Reserve Beauty</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin2.css">
</head>
<body class="strona-konta">
    <nav class="admin-menu" aria-label="Menu administratora">
        <ul class="admin-menu_lista">
            <?php foreach ($menu as $plik => $etykieta): ?>
                <li><a href="<?= e(BASE_URL . '/admin/' . $plik) ?>" <?= $plik === 'pracownicy.php' ? 'aria-current="page"' : '' ?>><?= e($etykieta) ?></a></li>
            <?php endforeach; ?>
            <li><a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a></li>
        </ul>
    </nav>
    <main>
        <div class="pole">
            <div>
                <p class="marka">RESERVE BEAUTY</p>
                <h1>Pracownicy</h1>
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

        <section class="karta" aria-labelledby="lista-tytul">
            <h2 id="lista-tytul">Lista pracowników</h2>
            <p>Aktywność pracownika dotyczy przyjmowania nowych wizyt. Aktywność konta określa możliwość logowania.</p>
            <div class="tabela">
                <table>
                    <thead><tr><th scope="col">Imię i nazwisko</th><th scope="col">Salon</th><th scope="col">Aktywność</th><th scope="col">Działania</th></tr></thead>
                    <tbody>
                        <?php foreach ($pracownicy as $pracownik): ?>
                            <tr>
                                <td class="tekst">
                                    <strong><?= e($pracownik['imie'] . ' ' . $pracownik['nazwisko']) ?></strong><br>
                                    <?= e($pracownik['email']) ?><br>
                                    <?= e($pracownik['telefon']) ?>
                                </td>
                                <td class="tekst">
                                    <?= e($pracownik['salon_nazwa'] . ' — ' . $pracownik['miasto']) ?>
                                    <?php if (!empty($pracownik['opis'])): ?>
                                        <div class="prac-opis"><?= e($pracownik['opis']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    Pracownik: <?= (int) $pracownik['aktywnosc'] === 1 ? 'aktywny' : 'nieaktywny' ?><br>
                                    Konto: <?= (int) $pracownik['aktywny'] === 1 ? 'aktywne' : 'zablokowane' ?>
                                </td>
                                <td>
                                    <div class="akcje">
                                        <a class="link" href="<?= e(BASE_URL) ?>/admin/pracownicy.php?edytuj=<?= (int) $pracownik['id_pracownika'] ?>#formularz">Edytuj</a>
                                        <form action="<?= e(BASE_URL) ?>/admin/pracownicy.php" method="post">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="akcja" value="pracownik_stan">
                                            <input type="hidden" name="id_pracownika" value="<?= (int) $pracownik['id_pracownika'] ?>">
                                            <input type="hidden" name="stan" value="<?= (int) $pracownik['aktywnosc'] === 1 ? '0' : '1' ?>">
                                            <button type="submit"><?= (int) $pracownik['aktywnosc'] === 1 ? 'Wyłącz pracownika' : 'Włącz pracownika' ?></button>
                                        </form>
                                        <form action="<?= e(BASE_URL) ?>/admin/pracownicy.php" method="post" onsubmit="return confirm('Czy zmienić możliwość logowania tego pracownika?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="akcja" value="konto_stan">
                                            <input type="hidden" name="id_pracownika" value="<?= (int) $pracownik['id_pracownika'] ?>">
                                            <input type="hidden" name="stan" value="<?= (int) $pracownik['aktywny'] === 1 ? '0' : '1' ?>">
                                            <button type="submit"><?= (int) $pracownik['aktywny'] === 1 ? 'Zablokuj logowanie' : 'Odblokuj logowanie' ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$pracownicy): ?>
                            <tr><td colspan="4"><?= $odczytOK ? 'Brak pracowników.' : 'Lista pracowników jest niedostępna.' ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="karta" id="formularz" aria-labelledby="formularz-tytul">
            <div class="akcje">
                <a class="link" href="<?= e(BASE_URL) ?>/admin/pracownicy.php?tryb=przypisz#formularz">Przypisz istniejące konto</a>
                <a class="link" href="<?= e(BASE_URL) ?>/admin/pracownicy.php?tryb=nowy#formularz">Utwórz nowe konto pracownika</a>
            </div>
            <h2 id="formularz-tytul"><?= e($tytuly[$tryb]) ?></h2>
            <?php if ($tryb === 'edytuj'): ?>
                <p>Edytujesz profil pracownika nr <?= (int) $formularz['id_pracownika'] ?><?= $formularz['imie'] !== '' ? ': ' . e($formularz['imie'] . ' ' . $formularz['nazwisko']) : '' ?>.</p>
            <?php endif; ?>
            <?php if (!$salony): ?>
                <p>Najpierw dodaj salon w zakładce Salony.</p>
            <?php endif; ?>
            <?php if ($tryb === 'przypisz' && !$konta): ?>
                <p>Nie ma wolnych kont z rolą pracownik. Wybierz „Utwórz nowe konto pracownika”.</p>
            <?php endif; ?>
            <form class="form" action="<?= e(BASE_URL) ?>/admin/pracownicy.php#formularz" method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="akcja" value="<?= e($tryb) ?>">
                <input type="hidden" name="id_pracownika" value="<?= (int) $formularz['id_pracownika'] ?>">
                <?php if ($tryb === 'przypisz'): ?>
                    <label>Konto pracownika
                        <select name="id_uzytkownika" required>
                            <option value="">Wybierz konto</option>
                            <?php foreach ($konta as $konto): ?>
                                <option value="<?= (int) $konto['id_uzytkownika'] ?>" <?= (string) $formularz['id_uzytkownika'] === (string) $konto['id_uzytkownika'] ? 'selected' : '' ?>><?= e($konto['imie'] . ' ' . $konto['nazwisko'] . ' — ' . $konto['email']) ?><?= (int) $konto['aktywny'] === 1 ? '' : ' (konto zablokowane)' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php elseif ($tryb === 'nowy'): ?>
                    <label>Imię (maks. 50 znaków)
                        <input type="text" name="imie" maxlength="50" required value="<?= e($formularz['imie']) ?>">
                    </label>
                    <label>Nazwisko (maks. 50 znaków)
                        <input type="text" name="nazwisko" maxlength="50" required value="<?= e($formularz['nazwisko']) ?>">
                    </label>
                    <label>E-mail (maks. 100 znaków)
                        <input type="email" name="email" maxlength="100" required value="<?= e($formularz['email']) ?>">
                    </label>
                    <label>Telefon (9–15 cyfr, opcjonalnie +)
                        <input type="tel" name="telefon" required value="<?= e($formularz['telefon']) ?>">
                    </label>
                    <label>Hasło (min. 12 znaków, maks. 72 bajty)
                        <input type="password" name="haslo" minlength="12" autocomplete="new-password" required>
                    </label>
                    <label>Powtórz hasło
                        <input type="password" name="powtorz_haslo" minlength="12" autocomplete="new-password" required>
                    </label>
                    <label class="wybor">
                        <input type="checkbox" name="aktywny" value="1" <?= (int) $formularz['aktywny'] === 1 ? 'checked' : '' ?>>
                        Konto aktywne — pracownik może się logować
                    </label>
                <?php endif; ?>
                <label>Salon
                    <select name="id_salonu" required>
                        <option value="">Wybierz salon</option>
                        <?php foreach ($salony as $salon): ?>
                            <option value="<?= (int) $salon['id_salonu'] ?>" <?= (string) $formularz['id_salonu'] === (string) $salon['id_salonu'] ? 'selected' : '' ?>><?= e($salon['nazwa'] . ' — ' . $salon['miasto']) ?><?= (int) $salon['aktywnosc'] === 1 ? '' : ' (nieaktywny)' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Opis zawodowy (opcjonalny)
                    <textarea name="opis" rows="4"><?= e($formularz['opis'] ?? '') ?></textarea>
                </label>
                <label class="wybor">
                    <input type="checkbox" name="aktywnosc" value="1" <?= (int) $formularz['aktywnosc'] === 1 ? 'checked' : '' ?>>
                    Pracownik aktywny — przyjmuje nowe wizyty
                </label>
                <div class="akcje">
                    <button type="submit" <?= !$salony || ($tryb === 'przypisz' && !$konta) ? 'disabled' : '' ?>><?= $tryb === 'nowy' ? 'Utwórz konto i profil' : 'Zapisz' ?></button>
                    <?php if ($tryb === 'edytuj'): ?>
                        <a class="prac-link" href="<?= e(BASE_URL) ?>/admin/pracownicy.php">Anuluj edycję</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>
    </main>
</body>
</html>
