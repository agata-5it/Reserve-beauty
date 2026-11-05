<?php
require_once __DIR__ . '/../includes/auth.php';
wymagajRoli('admin');

function kalTekst(string $pole): string
{
    return isset($_GET[$pole]) && is_string($_GET[$pole])
        ? trim($_GET[$pole])
        : '';
}

function kalId(string $pole): int
{
    return (int) (filter_var(kalTekst($pole), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647]
    ]) ?: 0);
}

function kalAdres(string $miesiac, int $salon, int $pracownik): string
{
    return BASE_URL . '/admin/kalendarz.php?' . http_build_query([
        'miesiac' => $miesiac,
        'salon' => $salon,
        'pracownik' => $pracownik
    ]);
}

$strefa = new DateTimeZone('Europe/Warsaw');
$dzis = new DateTimeImmutable('today', $strefa);

$miesiac = kalTekst('miesiac');
if (!preg_match('/\A(?:19|20|21)[0-9]{2}-(?:0[1-9]|1[0-2])\z/', $miesiac)) {
    $miesiac = $dzis->format('Y-m');
}

$poczatek = new DateTimeImmutable($miesiac . '-01', $strefa);
$koniec = $poczatek->modify('last day of this month');
$poprzedni = $poczatek->modify('-1 month')->format('Y-m');
$nastepny = $poczatek->modify('+1 month')->format('Y-m');

$idSalonu = kalId('salon');
$idPracownika = kalId('pracownik');

$miesiace = [
    1 => 'Styczeń', 'Luty', 'Marzec', 'Kwiecień',
    'Maj', 'Czerwiec', 'Lipiec', 'Sierpień',
    'Wrzesień', 'Październik', 'Listopad', 'Grudzień'
];

$tytul = $miesiace[(int) $poczatek->format('n')]
    . ' ' . $poczatek->format('Y');

$dniTygodnia = ['Pon', 'Wt', 'Śr', 'Czw', 'Pt', 'Sob', 'Ndz'];

$salony = [];
$pracownicy = [];
$widoczni = [];
$grafik = [];
$urlopy = [];
$blad = '';

try {
    $salony = $pdo->query(
        'SELECT id_salonu, nazwa, miasto
         FROM salony
         ORDER BY nazwa, miasto'
    )->fetchAll();

    $pracownicy = $pdo->query(
        'SELECT p.id_pracownika, p.id_salonu, p.aktywnosc,
                u.imie, u.nazwisko, s.nazwa AS salon
         FROM pracownicy p
         JOIN uzytkownicy u
             ON u.id_uzytkownika = p.id_uzytkownika
         JOIN salony s
             ON s.id_salonu = p.id_salonu
         ORDER BY u.nazwisko, u.imie'
    )->fetchAll();

    // Oba filtry obowiązują jednocześnie.
    foreach ($pracownicy as $osoba) {
        if ($idSalonu && (int) $osoba['id_salonu'] !== $idSalonu) {
            continue;
        }

        if ($idPracownika &&
            (int) $osoba['id_pracownika'] !== $idPracownika) {
            continue;
        }

        $widoczni[(int) $osoba['id_pracownika']] = $osoba;
    }

    // Grafik jest tygodniowy: 1 = poniedziałek, 7 = niedziela.
    $zapytanie = $pdo->query(
        'SELECT id_pracownika, dzien_tygodnia,
                czas_rozpoczecia, czas_zakonczenia
         FROM dostepnosc_pracownikow
         ORDER BY dzien_tygodnia, czas_rozpoczecia'
    );

    foreach ($zapytanie->fetchAll() as $przedzial) {
        $id = (int) $przedzial['id_pracownika'];
        $dzien = (int) $przedzial['dzien_tygodnia'];

        if (isset($widoczni[$id])) {
            $grafik[$id][$dzien][] =
                substr($przedzial['czas_rozpoczecia'], 0, 5)
                . '–'
                . substr($przedzial['czas_zakonczenia'], 0, 5);
        }
    }

    // Pobieramy również urlopy zaczynające się w poprzednim miesiącu.
    $zapytanie = $pdo->prepare(
        "SELECT id_urlopu, id_pracownika, data_od, data_do
         FROM urlopy
         WHERE status = 'zaplanowany'
           AND data_od <= ?
           AND data_do >= ?
         ORDER BY data_od, id_urlopu"
    );

    $zapytanie->execute([
        $koniec->format('Y-m-d'),
        $poczatek->format('Y-m-d')
    ]);

    foreach ($zapytanie->fetchAll() as $urlop) {
        $id = (int) $urlop['id_pracownika'];

        if (!isset($widoczni[$id])) {
            continue;
        }

        // Rozpisujemy urlop na dni, wliczając początek i koniec.
        $od = max($urlop['data_od'], $poczatek->format('Y-m-d'));
        $do = min($urlop['data_do'], $koniec->format('Y-m-d'));

        $data = new DateTimeImmutable($od, $strefa);

        while ($data->format('Y-m-d') <= $do) {
            $urlopy[$data->format('Y-m-d')][$id][] = $urlop;
            $data = $data->modify('+1 day');
        }
    }
} catch (PDOException $wyjatek) {
    $blad = 'Nie udało się pobrać kalendarza. Spróbuj odświeżyć stronę.';
    error_log('Kalendarz: SQLSTATE ' . $wyjatek->getCode());
}

$menu = [
    'index.php' => 'Podsumowanie',
    'kategorie.php' => 'Kategorie',
    'salony.php' => 'Salony',
    'uslugi.php' => 'Usługi',
    'pracownicy.php' => 'Pracownicy',
    'dostepnosc.php' => 'Dostępność',
    'urlopy.php' => 'Urlopy',
    'kalendarz.php' => 'Kalendarz',
    'rezerwacje.php' => 'Rezerwacje'
];

$pusteNaPoczatku = (int) $poczatek->format('N') - 1;
$liczbaDni = (int) $koniec->format('j');
$liczbaKomorek = (int) ceil(($pusteNaPoczatku + $liczbaDni) / 7) * 7;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kalendarz — Reserve Beauty</title>

    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/admin2.css">

    <style>
        #kalendarz .kal-karta {
            margin-top: 24px;
            padding: 24px;
            background: white;
            border: 1px solid #e8dcdf;
            border-radius: 18px;
        }

        #kalendarz .kal-filtry {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            justify-content: stretch;
            gap: 16px;
        }

        #kalendarz .kal-filtry label {
            display: grid;
            gap: 6px;
            font-weight: bold;
        }

        #kalendarz .kal-filtry input,
        #kalendarz .kal-filtry select {
            box-sizing: border-box;
            width: 100%;
            min-width: 0;
            padding: 11px;
            border: 1px solid #d9c5ce;
            border-radius: 9px;
            background: white;
            font: inherit;
        }

        #kalendarz .kal-przyciski {
            grid-column: 1 / -1;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }

        #kalendarz .kal-link {
            display: inline-block;
            padding: 9px 15px;
            border-radius: 9px;
            background: #f5e8ee;
            color: #76334b;
            text-decoration: none;
            font-weight: bold;
        }

        #kalendarz .kal-link:hover {
            background: #ead3dd;
        }

        #kalendarz .kal-nawigacja {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }

        #kalendarz .kal-nawigacja h2 {
            margin: 0;
        }

        #kalendarz .kal-legenda {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }

        #kalendarz .kal-etykieta {
            padding: 5px 10px;
            border-radius: 7px;
            font-size: 13px;
        }

        #kalendarz .kal-przewijanie {
            overflow-x: auto;
        }

        #kalendarz .kal-siatka {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            min-width: 980px;
            gap: 7px;
        }

        #kalendarz .kal-naglowek {
            padding: 10px;
            text-align: center;
            font-weight: bold;
            color: #76334b;
        }

        #kalendarz .kal-dzien {
            min-height: 140px;
            padding: 9px;
            border: 1px solid #e8dcdf;
            border-radius: 10px;
            background: #fff;
        }

        #kalendarz .kal-pusty {
            background: #faf7f8;
            border-color: transparent;
        }

        #kalendarz .kal-dzis {
            border: 2px solid #873b57;
            background: #fffafd;
        }

        #kalendarz .kal-numer {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }

        #kalendarz .kal-wpis {
            display: block;
            margin-bottom: 7px;
            padding: 7px;
            border-radius: 7px;
            font-size: 12px;
            line-height: 1.45;
            text-decoration: none;
            overflow-wrap: anywhere;
        }

        #kalendarz .kal-wpis small {
            display: block;
        }

        #kalendarz .kal-urlop {
            background: #f5dce7;
            color: #742c49;
        }

        #kalendarz a.kal-urlop:hover {
            background: #eec4d6;
        }

        #kalendarz .kal-wolne {
            background: #edf0f5;
            color: #43536b;
        }

        #kalendarz .kal-brak {
            background: #fff2d6;
            color: #745317;
        }

        #kalendarz .kal-praca {
            background: #e7f2ea;
            color: #315b3e;
        }

        #kalendarz .kal-opis {
            color: #6b5962;
            font-size: 14px;
        }

        #kalendarz a:focus-visible {
            outline: 3px solid #873b57;
            outline-offset: 2px;
        }

        @media (max-width: 700px) {
            #kalendarz .kal-filtry {
                grid-template-columns: 1fr;
            }

            #kalendarz .kal-karta {
                padding: 16px;
            }
        }
    </style>
</head>
<body class="strona-konta">
    <nav class="admin-menu" aria-label="Menu administratora">
        <ul class="admin-menu_lista">
            <?php foreach ($menu as $plik => $etykieta): ?>
                <li>
                    <a
                        href="<?= e(BASE_URL . '/admin/' . $plik) ?>"
                        <?= $plik === 'kalendarz.php' ? 'aria-current="page"' : '' ?>
                    >
                        <?= e($etykieta) ?>
                    </a>
                </li>
            <?php endforeach; ?>

            <li>
                <a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a>
            </li>
        </ul>
    </nav>

    <main id="kalendarz">
        <section class="kal-karta">
            <h1>Kalendarz urlopów i dni wolnych</h1>
            <p>Wybierz miesiąc, salon lub konkretną osobę.</p>

            <form class="kal-filtry" method="get">
                <label>
                    Miesiąc
                    <input
                        type="month"
                        name="miesiac"
                        min="1900-01"
                        max="2199-12"
                        value="<?= e($miesiac) ?>"
                        required
                    >
                </label>

                <label>
                    Salon
                    <select name="salon">
                        <option value="0">Wszystkie salony</option>

                        <?php foreach ($salony as $salon): ?>
                            <option
                                value="<?= (int) $salon['id_salonu'] ?>"
                                <?= (int) $salon['id_salonu'] === $idSalonu ? 'selected' : '' ?>
                            >
                                <?= e($salon['nazwa'] . ' — ' . $salon['miasto']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Pracownik
                    <select name="pracownik">
                        <option value="0">Wszyscy pracownicy</option>

                        <?php foreach ($pracownicy as $osoba): ?>
                            <option
                                value="<?= (int) $osoba['id_pracownika'] ?>"
                                <?= (int) $osoba['id_pracownika'] === $idPracownika ? 'selected' : '' ?>
                            >
                                <?= e(
                                    $osoba['imie'] . ' ' . $osoba['nazwisko']
                                    . ' — ' . $osoba['salon']
                                ) ?>
                                <?= (int) $osoba['aktywnosc'] === 1 ? '' : ' (nieaktywny)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="kal-przyciski">
                    <button type="submit">Pokaż kalendarz</button>

                    <a class="kal-link" href="<?= e(BASE_URL) ?>/admin/kalendarz.php">
                        Wyczyść filtry
                    </a>

                    <a class="kal-link" href="<?= e(BASE_URL) ?>/admin/urlopy.php">
                        Dodaj urlop
                    </a>
                </div>
            </form>
        </section>

        <section class="kal-karta">
            <div class="kal-nawigacja">
                <?php if ($miesiac > '1900-01'): ?>
                    <a
                        class="kal-link"
                        href="<?= e(kalAdres($poprzedni, $idSalonu, $idPracownika)) ?>"
                    >
                        ← Poprzedni
                    </a>
                <?php endif; ?>

                <h2><?= e($tytul) ?></h2>

                <?php if ($miesiac < '2199-12'): ?>
                    <a
                        class="kal-link"
                        href="<?= e(kalAdres($nastepny, $idSalonu, $idPracownika)) ?>"
                    >
                        Następny →
                    </a>
                <?php endif; ?>
            </div>

            <div class="kal-legenda">
                <span class="kal-etykieta kal-urlop">Urlop</span>
                <span class="kal-etykieta kal-wolne">Wolne według grafiku</span>
                <span class="kal-etykieta kal-brak">Brak ustalonego grafiku</span>

                <?php if ($idPracownika): ?>
                    <span class="kal-etykieta kal-praca">Godziny pracy</span>
                <?php endif; ?>
            </div>

            <p class="kal-opis">
                Kliknij urlop, aby otworzyć jego szczegóły i edycję.
                Dni wolne wynikają z obecnego grafiku tygodniowego.
                Jeśli pracownik nie ma żadnego grafiku, pokazujemy „Brak grafiku”.
                Godziny pracy są widoczne po wybraniu jednej osoby.
            </p>

            <?php if ($blad): ?>
                <p class="blad" role="alert"><?= e($blad) ?></p>
            <?php elseif (!$widoczni): ?>
                <p>Brak pracowników pasujących do wybranych filtrów.</p>
            <?php else: ?>
                <div class="kal-przewijanie">
                    <div class="kal-siatka">
                        <?php foreach ($dniTygodnia as $nazwaDnia): ?>
                            <div class="kal-naglowek">
                                <?= e($nazwaDnia) ?>
                            </div>
                        <?php endforeach; ?>

                        <?php for ($komorka = 0; $komorka < $liczbaKomorek; $komorka++): ?>
                            <?php
                            $numer = $komorka - $pusteNaPoczatku + 1;
                            ?>

                            <?php if ($numer < 1 || $numer > $liczbaDni): ?>
                                <div class="kal-dzien kal-pusty" aria-hidden="true"></div>
                                <?php continue; ?>
                            <?php endif; ?>

                            <?php
                            $data = $poczatek->modify('+' . ($numer - 1) . ' days');
                            $klucz = $data->format('Y-m-d');
                            $dzienTygodnia = (int) $data->format('N');
                            $czyDzis = $klucz === $dzis->format('Y-m-d');
                            $liczbaWpisow = 0;
                            ?>

                            <div class="kal-dzien <?= $czyDzis ? 'kal-dzis' : '' ?>">
                                <time class="kal-numer" datetime="<?= e($klucz) ?>">
                                    <?= $numer ?>
                                    <?= $czyDzis ? ' · dziś' : '' ?>
                                </time>

                                <?php foreach ($widoczni as $id => $osoba): ?>
                                    <?php
                                    $nazwa = $osoba['imie'] . ' ' . $osoba['nazwisko'];
                                    ?>

                                    <?php if (!empty($urlopy[$klucz][$id])): ?>
                                        <?php foreach ($urlopy[$klucz][$id] as $urlop): ?>
                                            <a
                                                class="kal-wpis kal-urlop"
                                                href="<?= e(BASE_URL) ?>/admin/urlopy.php?edytuj=<?= (int) $urlop['id_urlopu'] ?>#formularz"
                                            >
                                                <strong><?= e($nazwa) ?></strong>
                                                <small><?= e($osoba['salon']) ?></small>
                                                <small>Urlop</small>
                                                <small>
                                                    <?= e($urlop['data_od']) ?>
                                                    – <?= e($urlop['data_do']) ?>
                                                </small>
                                            </a>
                                            <?php $liczbaWpisow++; ?>
                                        <?php endforeach; ?>

                                    <?php elseif (empty($grafik[$id])): ?>
                                        <a
                                            class="kal-wpis kal-brak"
                                            href="<?= e(BASE_URL) ?>/admin/dostepnosc.php?id_pracownika=<?= $id ?>"
                                        >
                                            <strong><?= e($nazwa) ?></strong>
                                            <small>Brak grafiku</small>
                                        </a>
                                        <?php $liczbaWpisow++; ?>

                                    <?php elseif (empty($grafik[$id][$dzienTygodnia])): ?>
                                        <a
                                            class="kal-wpis kal-wolne"
                                            href="<?= e(BASE_URL) ?>/admin/dostepnosc.php?id_pracownika=<?= $id ?>"
                                        >
                                            <strong><?= e($nazwa) ?></strong>
                                            <small>Wolne według grafiku</small>
                                        </a>
                                        <?php $liczbaWpisow++; ?>

                                    <?php elseif ($idPracownika): ?>
                                        <a
                                            class="kal-wpis kal-praca"
                                            href="<?= e(BASE_URL) ?>/admin/dostepnosc.php?id_pracownika=<?= $id ?>"
                                        >
                                            <strong><?= e($nazwa) ?></strong>
                                            <small>
                                                <?= e(implode(', ', $grafik[$id][$dzienTygodnia])) ?>
                                            </small>
                                        </a>
                                        <?php $liczbaWpisow++; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>

                                <?php if (!$liczbaWpisow): ?>
                                    <small class="kal-opis">Brak nieobecności</small>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>