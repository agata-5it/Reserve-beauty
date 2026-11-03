<?php

require_once __DIR__ . '/../includes/auth.php';

$uzytkownik = wymagajRoli('admin');

$teraz = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));
$dzis = $teraz->format('Y-m-d');
$jutro = $teraz->modify('+1 day')->format('Y-m-d');

$liczniki = $wizyty = $urlopy = null;
try {
    // Klienci to konta z rolą klient, a pracownicy to profile zawodowe.
    $stmt = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM uzytkownicy WHERE rola = 'klient') AS klienci,
        (SELECT COUNT(*) FROM pracownicy) AS pracownicy,
        (SELECT COUNT(*) FROM uslugi WHERE aktywnosc = 1) AS uslugi,
        (SELECT COUNT(*) FROM rezerwacje) AS rezerwacje,
        (SELECT COUNT(*) FROM rezerwacje
         WHERE status <> 'anulowana'
           AND data_rozpoczecia >= ? AND data_rozpoczecia < ?) AS dzisiejsze");
    $stmt->execute([$dzis . ' 00:00:00', $jutro . ' 00:00:00']);
    $liczniki = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT r.data_rozpoczecia, u.nazwa AS usluga, s.nazwa AS salon,
            CONCAT(k.imie, ' ', k.nazwisko) AS klient
        FROM rezerwacje r
        JOIN uzytkownicy k ON k.id_uzytkownika = r.id_uzytkownika
        JOIN uslugi u ON u.id_uslugi = r.id_uslugi
        JOIN salony s ON s.id_salonu = u.id_salonu
        WHERE r.status IN ('oczekujaca', 'potwierdzona') AND r.data_rozpoczecia >= ?
        ORDER BY r.data_rozpoczecia, r.nr_rezerwacji LIMIT 5");
    $stmt->execute([$teraz->format('Y-m-d H:i:s')]);
    $wizyty = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT w.data_od, w.data_do, s.nazwa AS salon,
            CONCAT(k.imie, ' ', k.nazwisko) AS pracownik
        FROM urlopy w
        JOIN pracownicy p ON p.id_pracownika = w.id_pracownika
        JOIN uzytkownicy k ON k.id_uzytkownika = p.id_uzytkownika
        JOIN salony s ON s.id_salonu = p.id_salonu
        WHERE w.status = 'zaplanowany' AND w.data_od >= ?
        ORDER BY w.data_od, w.id_urlopu LIMIT 5");
    $stmt->execute([$dzis]);
    $urlopy = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log('Podsumowanie: SQLSTATE ' . $ex->getCode());
}

$etykiety = [
    'klienci' => 'Klienci',
    'pracownicy' => 'Pracownicy',
    'uslugi' => 'Aktywne usługi',
    'rezerwacje' => 'Wszystkie rezerwacje',
    'dzisiejsze' => 'Dzisiejsze wizyty bez anulowanych',
];

function panelData(string $wartosc, string $format): string
{
    return (new DateTimeImmutable($wartosc, new DateTimeZone('Europe/Warsaw')))->format($format);
}

?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel administratora — Reserve Beauty</title>
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        #panel .kafelki, #panel .podglad { display: grid; gap: 14px; }
        #panel .kafelki { grid-template-columns: repeat(auto-fit, minmax(min(100%, 185px), 1fr)); }
        #panel .podglad { grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr)); margin-top: 24px; }
        #panel .kafelek, #panel .karta { background: white; border: 1px solid #e8dcdf; border-radius: 16px; padding: 20px; }
        #panel .kafelek dt { color: #59474f; }
        #panel .kafelek dd { margin: 6px 0 0; font-size: 2rem; font-weight: bold; color: #873b57; }
        #panel .karta h2 { margin-top: 0; }
        #panel .karta ul { padding: 0; list-style: none; }
        #panel .karta li { padding: 10px 0; border-bottom: 1px solid #eee3e7; }
        #panel .karta small { display: block; }
        #panel .karta a { color: #76334b; }
    </style>
</head>

<body class="strona-konta">
    <nav class="admin-menu" aria-label="Menu administratora">
            <ul class="admin-menu_lista">
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/index.php" aria-current="page">Podsumowanie</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/kategorie.php">Kategorie</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/salony.php">Salony</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/uslugi.php">Usługi</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/pracownicy.php">Pracownicy</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/dostepnosc.php">Dostępność</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/urlopy.php">Urlopy</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/admin/rezerwacje.php">Rezerwacje</a>
                </li>
                <li>
                    <a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a>
                </li>
            </ul>
        </nav>
    <main id="panel">
        <div class="pole">
            <div>
                <p class="marka">RESERVE BEAUTY</p>
                <h1>Panel administratora</h1>
            </div>
            <form
                action="<?= e(BASE_URL) ?>/wylogowanie.php"
                method="post"
                >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['csrf_token']) ?>"
                >

                <button type="submit">Wyloguj się</button>
            </form>
        </div><br>

        <p>Witaj, <?= e($uzytkownik['imie']) ?>!</p>
        <p>Jesteś zalogowana lub zalogowany na konto:<?= e($uzytkownik['email']) ?></p>
        
        <section aria-labelledby="liczniki">
            <h2 id="liczniki">Podsumowanie na <?= e($teraz->format('d.m.Y')) ?></h2>
            <?php if ($liczniki === null): ?>
                <p role="alert">Nie udało się pobrać liczników. Odśwież stronę, aby spróbować ponownie.</p>
            <?php else: ?>
                <dl class="kafelki">
                    <?php foreach ($etykiety as $klucz => $etykieta): ?>
                        <div class="kafelek">
                            <dt><?= e($etykieta) ?></dt>
                            <dd><?= (int) $liczniki[$klucz] ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </section>
        <div class="podglad">
            <section class="karta">
                <h2>Najbliższe wizyty</h2>
                <?php if ($wizyty === null): ?>
                    <p>Nie udało się pobrać wizyt.</p>
                <?php elseif (!$wizyty): ?>
                    <p>Nie ma nadchodzących wizyt.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($wizyty as $w): ?>
                            <li><strong><?= e(panelData($w['data_rozpoczecia'], 'd.m.Y H:i')) ?></strong>
                                · <?= e($w['usluga']) ?>
                                <small><?= e($w['klient'] . ' — ' . $w['salon']) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <a href="<?= e(BASE_URL) ?>/admin/rezerwacje.php">Wszystkie rezerwacje</a>
            </section>
            <section class="karta">
                <h2>Nadchodzące urlopy</h2>
                <?php if ($urlopy === null): ?>
                    <p>Nie udało się pobrać urlopów.</p>
                <?php elseif (!$urlopy): ?>
                    <p>Nie ma zaplanowanych urlopów rozpoczynających się dziś lub później.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($urlopy as $w): ?>
                            <li><strong><?= e($w['pracownik']) ?></strong> · <?= e($w['salon']) ?>
                                <small><?= e(panelData($w['data_od'], 'd.m.Y')) ?> – <?= e(panelData($w['data_do'], 'd.m.Y')) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <a href="<?= e(BASE_URL) ?>/admin/urlopy.php">Wszystkie urlopy</a>
            </section>
        </div>
    </main>
</body>
</html>