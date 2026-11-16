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

    <title>Oferta usług — Reserve Beauty</title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/klient.css?v=2">
</head>

<body class="strona-klienta oferta">
    <?php $osobaMenu = zalogowanyUzytkownik(); ?>
    <nav class="menu-klienta" aria-label="Menu główne">
        <ul>
            <?php if ($osobaMenu && $osobaMenu['rola'] === 'klient'): ?>
                <li><a href="<?= e(BASE_URL) ?>/klient/index.php">Podsumowanie</a></li>
                <li><a href="<?= e(BASE_URL) ?>/klient/index.php#wizyty">Moje rezerwacje</a></li>
            <?php elseif ($osobaMenu): ?>
                <li><a href="<?= e(BASE_URL . adresPanelu($osobaMenu['rola'])) ?>">Mój panel</a></li>
            <?php else: ?>
                <li><a href="<?= e(BASE_URL) ?>/index.php">Strona główna</a></li>
            <?php endif; ?>
            <li><a href="<?= e(BASE_URL) ?>/oferta.php" aria-current="page">Oferta usług</a></li>
            <?php if ($osobaMenu): ?>
                <li><a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a></li>
            <?php else: ?>
                <li><a href="<?= e(BASE_URL) ?>/logowanie.php">Zaloguj się</a></li>
            <?php endif; ?>
        </ul>
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