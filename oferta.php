<?php
require_once __DIR__ . '/includes/auth.php';

// Nie wywołujemy wymagajRoli(), ponieważ oferta jest publiczna.

// Brak filtra oznacza 0. Nieprawidłowa wartość oznacza null.
function ofertaFiltrId(string $nazwa): ?int
{
    $wartosc = $_GET[$nazwa] ?? '';

    if ($wartosc === '' || $wartosc === '0') {
        return 0;
    }

    if (!is_string($wartosc)) {
        return null;
    }

    $id = filter_var($wartosc, FILTER_VALIDATE_INT, [
        'options' => [
            'min_range' => 1,
            'max_range' => 2147483647
        ]
    ]);

    return $id === false ? null : $id;
}

$idSalonu = ofertaFiltrId('salon');
$idKategorii = ofertaFiltrId('kategoria');

$salony = [];
$kategorie = [];
$uslugi = [];
$blad = '';

try {
    // Listy wyboru zawierają tylko aktywne salony i kategorie.
    $salony = $pdo->query(
        'SELECT id_salonu, nazwa, miasto
         FROM salony
         WHERE aktywnosc = 1
         ORDER BY miasto, nazwa, id_salonu'
    )->fetchAll(PDO::FETCH_ASSOC);

    $kategorie = $pdo->query(
        'SELECT id, nazwa
         FROM kategorie_uslug
         WHERE aktywny = 1
         ORDER BY nazwa, id'
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($idSalonu === null || $idKategorii === null) {
        $blad = 'Nieprawidłowy filtr. Wybierz salon i kategorię z listy.';
        http_response_code(400);
    }

    // Sprawdzamy również wartości wpisane ręcznie w adresie strony.
    if ($blad === '' && $idSalonu > 0) {
        $istnieje = false;

        foreach ($salony as $salon) {
            if ((int) $salon['id_salonu'] === $idSalonu) {
                $istnieje = true;
                break;
            }
        }

        if (!$istnieje) {
            $blad = 'Wybrany salon nie istnieje lub jest nieaktywny.';
            http_response_code(400);
        }
    }

    if ($blad === '' && $idKategorii > 0) {
        $istnieje = false;

        foreach ($kategorie as $kategoria) {
            if ((int) $kategoria['id'] === $idKategorii) {
                $istnieje = true;
                break;
            }
        }

        if (!$istnieje) {
            $blad = 'Wybrana kategoria nie istnieje lub jest nieaktywna.';
            http_response_code(400);
        }
    }

    if ($blad === '') {
        $sql = '
            SELECT
                u.id_uslugi,
                u.nazwa,
                u.cena,
                u.czas_trwania,
                k.nazwa AS kategoria,
                s.nazwa AS salon,
                s.miasto
            FROM uslugi u
            JOIN kategorie_uslug k ON k.id = u.id_kategoria
            JOIN salony s ON s.id_salonu = u.id_salonu
            WHERE u.aktywnosc = 1
              AND k.aktywny = 1
              AND s.aktywnosc = 1
        ';

        $parametry = [];

        if ($idSalonu > 0) {
            $sql .= ' AND s.id_salonu = ?';
            $parametry[] = $idSalonu;
        }

        if ($idKategorii > 0) {
            $sql .= ' AND k.id = ?';
            $parametry[] = $idKategorii;
        }

        $sql .= ' ORDER BY k.nazwa, u.nazwa, s.nazwa, u.id_uslugi';

        $zapytanie = $pdo->prepare($sql);
        $zapytanie->execute($parametry);

        $uslugi = $zapytanie->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $wyjatek) {
    error_log('Oferta: SQLSTATE ' . $wyjatek->getCode());

    $blad = 'Nie udało się pobrać oferty. Spróbuj ponownie później.';
    http_response_code(503);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/klient.css">

    <title>Oferta usług — Reserve Beauty</title>

    <style>
        .menu {
            background: #ffffff;
            border-bottom: 1px solid #eadde3;
        }

        .menu-wnetrze {
            max-width: 1180px;
            margin: 0 auto;
            padding: 18px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .logo {
            color: #873b57;
            font-size: 22px;
            font-weight: bold;
            text-decoration: none;
        }

        .menu-lista {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .menu-lista a {
            display: block;
            padding: 8px 13px;
            border-radius: 8px;
            text-decoration: none;
        }

        .menu-lista a:hover,
        .menu-lista a[aria-current="page"] {
            background: #f5e8ee;
            color: #873b57;
        }

        main {
            max-width: 1180px;
            margin: 0 auto;
            padding: 40px 24px 60px;
        }

        .naglowek {
            margin-bottom: 28px;
        }

        .nadtytul {
            margin: 0 0 8px;
            color: #873b57;
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        h1 {
            margin: 0 0 10px;
            font-size: clamp(30px, 5vw, 44px);
            line-height: 1.2;
        }

        .wprowadzenie {
            max-width: 650px;
            margin: 0;
            color: #766570;
        }

        .filtry {
            margin-bottom: 28px;
            padding: 24px;
            background: #ffffff;
            border: 1px solid #eadde3;
            border-radius: 16px;
        }

        .filtry h2 {
            margin: 0 0 16px;
            font-size: 19px;
        }

        .formularz-filtrow {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
            align-items: end;
            gap: 16px;
        }

        label {
            display: block;
            font-size: 14px;
            font-weight: bold;
        }

        select {
            width: 100%;
            margin-top: 7px;
            padding: 12px;
            border: 1px solid #d9c5ce;
            border-radius: 9px;
            background: #ffffff;
            color: #382c33;
            font: inherit;
        }

        .akcje {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .przycisk {
            display: inline-block;
            padding: 12px 20px;
            border: 1px solid #873b57;
            border-radius: 9px;
            background: #873b57;
            color: #ffffff;
            font: inherit;
            font-weight: bold;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
        }

        .przycisk:hover {
            background: #6c2e45;
            border-color: #6c2e45;
        }

        .wyczysc {
            color: #873b57;
            font-size: 14px;
            text-underline-offset: 3px;
        }

        a:focus-visible,
        button:focus-visible,
        select:focus-visible {
            outline: 3px solid #bd819a;
            outline-offset: 3px;
        }

        .licznik {
            margin: 0 0 18px;
            color: #766570;
        }

        .lista-uslug {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 22px;
        }

        .karta-uslugi {
            display: flex;
            flex-direction: column;
            padding: 24px;
            border: 1px solid #eadde3;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 6px 20px rgba(70, 35, 50, 0.04);
            overflow-wrap: anywhere;
        }

        .kategoria {
            align-self: flex-start;
            margin-bottom: 16px;
            padding: 5px 11px;
            border-radius: 20px;
            background: #f5e8ee;
            color: #873b57;
            font-size: 12px;
            font-weight: bold;
        }

        .karta-uslugi h2 {
            margin: 0 0 16px;
            font-size: 22px;
            line-height: 1.35;
        }

        .salon {
            margin: 0;
            font-weight: bold;
        }

        .miasto {
            margin: 2px 0 22px;
            color: #766570;
            font-size: 14px;
        }

        .cena-czas {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: auto;
            padding-top: 18px;
            border-top: 1px solid #f0e6eb;
        }

        .cena {
            color: #873b57;
            font-size: 23px;
            font-weight: bold;
        }

        .czas {
            color: #766570;
            font-size: 14px;
        }

        .szczegoly {
            margin-top: 18px;
        }

        .komunikat {
            padding: 24px;
            border: 1px solid #eadde3;
            border-radius: 12px;
            background: #ffffff;
        }

        .komunikat h2 {
            margin-top: 0;
            font-size: 21px;
        }

        .blad {
            border-color: #e2b6bd;
            background: #fff1f3;
            color: #8b2939;
        }

        footer {
            padding: 22px;
            border-top: 1px solid #eadde3;
            color: #766570;
            text-align: center;
            font-size: 13px;
        }

        @media (max-width: 950px) {
            .lista-uslug {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .formularz-filtrow {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .formularz-filtrow .akcje {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 600px) {
            main {
                padding: 28px 16px 40px;
            }

            .menu-wnetrze {
                padding: 16px;
            }

            .menu-lista {
                gap: 4px;
            }

            .menu-lista a {
                padding: 7px 9px;
                font-size: 14px;
            }

            .formularz-filtrow,
            .lista-uslug {
                grid-template-columns: minmax(0, 1fr);
            }

            .filtry,
            .karta-uslugi {
                padding: 20px;
            }
        }
    </style>
</head>

<body>
    <nav class="menu" aria-label="Menu główne">
        <div class="menu-wnetrze">
            <a class="logo" href="<?= e(BASE_URL) ?>/index.php">
                Reserve Beauty
            </a>

            <ul class="menu-lista">
                <li>
                    <a href="<?= e(BASE_URL) ?>/index.php">
                        Strona główna
                    </a>
                </li>

                <li>
                    <a href="<?= e(BASE_URL) ?>/oferta.php"
                       aria-current="page">
                        Oferta
                    </a>
                </li>

                <li>
                    <a href="<?= e(BASE_URL) ?>/profil.php">
                        Mój profil
                    </a>
                </li>

                <li>
                    <a href="<?= e(BASE_URL) ?>/logowanie.php">
                        Zaloguj się
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <main>
        <header class="naglowek">
            <p class="nadtytul">Chwila dla Ciebie</p>

            <h1>Nasza oferta usług</h1>

            <p class="wprowadzenie">
                Wybierz salon i kategorię. Sprawdź ceny oraz czas trwania
                zabiegów i znajdź usługę dla siebie.
            </p>
        </header>

        <section class="filtry" aria-labelledby="tytul-filtrow">
            <h2 id="tytul-filtrow">Znajdź usługę</h2>

            <form class="formularz-filtrow"
                  method="get"
                  action="<?= e(BASE_URL) ?>/oferta.php">

                <label for="salon">
                    Salon

                    <select id="salon" name="salon">
                        <option value="0">Wszystkie salony</option>

                        <?php foreach ($salony as $salon): ?>
                            <option
                                value="<?= (int) $salon['id_salonu'] ?>"
                                <?= $idSalonu === (int) $salon['id_salonu']
                                    ? 'selected' : '' ?>
                            >
                                <?= e(
                                    $salon['nazwa'] . ' — ' . $salon['miasto']
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label for="kategoria">
                    Kategoria

                    <select id="kategoria" name="kategoria">
                        <option value="0">Wszystkie kategorie</option>

                        <?php foreach ($kategorie as $kategoria): ?>
                            <option
                                value="<?= (int) $kategoria['id'] ?>"
                                <?= $idKategorii === (int) $kategoria['id']
                                    ? 'selected' : '' ?>
                            >
                                <?= e($kategoria['nazwa']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="akcje">
                    <button class="przycisk" type="submit">
                        Pokaż usługi
                    </button>

                    <a class="wyczysc"
                       href="<?= e(BASE_URL) ?>/oferta.php">
                        Wyczyść filtry
                    </a>
                </div>
            </form>
        </section>

        <?php if ($blad !== ''): ?>

            <div class="komunikat blad" role="alert">
                <?= e($blad) ?>
            </div>

        <?php elseif (!$uslugi): ?>

            <section class="komunikat">
                <h2>Brak usług do wyświetlenia</h2>

                <p>
                    Nie znaleziono aktywnych usług dla wybranych filtrów.
                    Spróbuj wybrać inny salon lub kategorię.
                </p>

                <a class="wyczysc"
                   href="<?= e(BASE_URL) ?>/oferta.php">
                    Pokaż całą ofertę
                </a>
            </section>

        <?php else: ?>

            <p class="licznik">
                Liczba znalezionych usług:
                <strong><?= count($uslugi) ?></strong>
            </p>

            <div class="lista-uslug">
                <?php foreach ($uslugi as $usluga): ?>

                    <article class="karta-uslugi">
                        <span class="kategoria">
                            <?= e($usluga['kategoria']) ?>
                        </span>

                        <h2><?= e($usluga['nazwa']) ?></h2>

                        <p class="salon">
                            <?= e($usluga['salon']) ?>
                        </p>

                        <p class="miasto">
                            <?= e($usluga['miasto']) ?>
                        </p>

                        <div class="cena-czas">
                            <span class="cena">
                                <?= e(number_format(
                                    (float) $usluga['cena'],
                                    2,
                                    ',',
                                    ' '
                                )) ?> zł
                            </span>

                            <span class="czas">
                                <?= (int) $usluga['czas_trwania'] ?> min
                            </span>
                        </div>

                        <a
                            class="przycisk szczegoly"
                            href="<?= e(BASE_URL) ?>/usluga.php?id=<?= (int) $usluga['id_uslugi'] ?>"
                            aria-label="<?= e(
                                'Zobacz szczegóły: ' . $usluga['nazwa']
                                . ', ' . $usluga['salon']
                            ) ?>"
                        >
                            Zobacz szczegóły
                        </a>
                    </article>

                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </main>

    <footer>
        Reserve Beauty — system rezerwacji usług beauty
    </footer>
</body>
</html>