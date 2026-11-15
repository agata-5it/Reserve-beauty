<?php
require_once __DIR__ . '/../includes/auth.php';
$klient = wymagajRoli('klient');
require_once __DIR__ . '/../includes/rezerwacje.php';
header('Cache-Control: no-store');

// Identyfikatory z adresu służą tylko do wskazania wyboru.
function wyborId($wartosc): int
{
    if (!is_string($wartosc)) {
        return 0;
    }
    return (int) (filter_var($wartosc, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647]
    ]) ?: 0);
}

function wyborTekst(array $dane, string $pole): string
{
    return isset($dane[$pole]) && is_string($dane[$pole]) ? trim($dane[$pole]) : '';
}

// Zapis i podgląd korzystają z tego samego wyliczania dostępnych godzin.
// Przy zapisie odczyty są aktualne i odbywają się pod blokadami InnoDB.
function wyborZapisz(PDO $pdo, array $klient, array $oferta, string $uwagi): int
{
    $pdo->beginTransaction();
    try {
        // Wspólna kolejność z modułem urlopów: salon, potem pracownik.
        $q = $pdo->prepare('SELECT id_salonu FROM salony WHERE id_salonu = ? FOR UPDATE');
        $q->execute([$oferta['id_salonu']]);
        if (!$q->fetch()) {
            throw new RuntimeException('Salon nie jest już dostępny. Wybierz usługę ponownie.');
        }
        $q = $pdo->prepare('SELECT id_salonu FROM pracownicy WHERE id_pracownika = ? FOR UPDATE');
        $q->execute([$oferta['id_pracownika']]);
        if ((int) $q->fetchColumn() !== $oferta['id_salonu']) {
            throw new RuntimeException('Przypisanie pracownika do salonu zmieniło się. Wybierz pracownika ponownie.');
        }
        $q = $pdo->prepare(
            "SELECT us.cena, us.czas_trwania, us.id_salonu
             FROM uslugi us
             JOIN salony s ON s.id_salonu = us.id_salonu
             JOIN kategorie_uslug k ON k.id = us.id_kategoria
             JOIN pracownicy_uslugi pu ON pu.id_uslugi = us.id_uslugi
             JOIN pracownicy p ON p.id_pracownika = pu.id_pracownika AND p.id_salonu = us.id_salonu
             JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika
             WHERE us.id_uslugi = ? AND p.id_pracownika = ?
               AND us.aktywnosc = 1 AND s.aktywnosc = 1 AND k.aktywny = 1
               AND p.aktywnosc = 1 AND u.aktywny = 1 AND u.rola = 'pracownik'
             FOR UPDATE"
        );
        $q->execute([$oferta['id_uslugi'], $oferta['id_pracownika']]);
        $aktualna = $q->fetch(PDO::FETCH_ASSOC);
        if (!$aktualna || (int) $aktualna['id_salonu'] !== $oferta['id_salonu']) {
            throw new RuntimeException('Usługa nie jest już dostępna u tego pracownika w wybranym salonie.');
        }
        // Porównujemy z podsumowaniem zapisanym NA SERWERZE, nie z hidden input.
        if ((string) $aktualna['cena'] !== $oferta['cena']
            || (int) $aktualna['czas_trwania'] !== $oferta['czas_trwania']) {
            throw new RuntimeException('Cena lub czas usługi zmieniły się. Nie zapisano wizyty. Sprawdź aktualne warunki poniżej i potwierdź je ponownie.');
        }
        $q = $pdo->prepare("SELECT id_uzytkownika FROM uzytkownicy WHERE id_uzytkownika = ? AND aktywny = 1 AND rola = 'klient' FOR UPDATE");
        $q->execute([$klient['id_uzytkownika']]);
        if (!$q->fetch()) {
            throw new RuntimeException('Konto klienta nie jest już aktywne.');
        }
        $dzien = rezerwacjaData($oferta['data']);
        if (!$dzien) {
            throw new RuntimeException('Wybierz poprawną datę ponownie.');
        }
        $q = $pdo->prepare('SELECT czas_rozpoczecia, czas_zakonczenia FROM dostepnosc_pracownikow WHERE id_pracownika = ? AND dzien_tygodnia = ? ORDER BY czas_rozpoczecia FOR UPDATE');
        $q->execute([$oferta['id_pracownika'], (int) $dzien->format('N')]);
        $grafik = $q->fetchAll(PDO::FETCH_ASSOC);
        $q = $pdo->prepare("SELECT id_urlopu FROM urlopy WHERE id_pracownika = ? AND status = 'zaplanowany' AND data_od <= ? AND data_do >= ? FOR UPDATE");
        $q->execute([$oferta['id_pracownika'], $oferta['data'], $oferta['data']]);
        $urlop = count($q->fetchAll(PDO::FETCH_ASSOC)) > 0;
        $q = $pdo->prepare("SELECT data_rozpoczecia, data_zakonczenia FROM rezerwacje WHERE id_pracownika = ? AND status <> 'anulowana' AND data_rozpoczecia < ? AND data_zakonczenia > ? FOR UPDATE");
        $q->execute([$oferta['id_pracownika'], $dzien->modify('+1 day')->format('Y-m-d H:i:s'), $dzien->format('Y-m-d H:i:s')]);
        $wizyty = $q->fetchAll(PDO::FETCH_ASSOC);
        $termin = null;
        foreach (rezerwacjaWyliczGodziny($oferta['data'], (int) $aktualna['czas_trwania'], $grafik, $wizyty, $urlop) as $wolny) {
            if ($wolny['godzina'] === $oferta['godzina']) {
                $termin = $wolny;
                break;
            }
        }
        if (!$termin) {
            throw new RuntimeException('Wybrany termin nie jest już dostępny. Wybierz inną godzinę.');
        }
        $q = $pdo->prepare(
            "INSERT INTO rezerwacje
             (id_uzytkownika, id_pracownika, id_uslugi, data_rozpoczecia, data_zakonczenia, cena, status, uwagi)
             VALUES (?, ?, ?, ?, ?, ?, 'oczekujaca', ?)"
        );
        $q->execute([$klient['id_uzytkownika'], $oferta['id_pracownika'], $oferta['id_uslugi'],
            $termin['data_rozpoczecia'], $termin['data_zakonczenia'], $aktualna['cena'], $uwagi === '' ? null : $uwagi]);
        $numer = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $numer;
    } catch (Throwable $wyjatek) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $wyjatek;
    }
}

$wejscie = $_GET;
$bladZapisu = '';
$uwagi = '';
$czyPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$sukces = $_SESSION['wybor_sukces'] ?? '';
unset($_SESSION['wybor_sukces']);
$_SESSION['wybor_oferty'] = $_SESSION['wybor_oferty'] ?? [];
// Podsumowania są ważne 30 minut; osobne karty mają osobne tokeny.
foreach ($_SESSION['wybor_oferty'] as $klucz => $stara) {
    if ($stara['wygasa'] < time()) { unset($_SESSION['wybor_oferty'][$klucz]); }
}
if ($czyPost) {
    try {
        if (!sprawdzCsrf()) {
            throw new RuntimeException('Formularz wygasł. Wybierz usługę i termin ponownie.');
        }
        $token = wyborTekst($_POST, 'oferta_token');
        $oferta = $_SESSION['wybor_oferty'][$token] ?? null;
        if (!$oferta || $oferta['id_klienta'] !== (int) $klient['id_uzytkownika']) {
            throw new RuntimeException('Podsumowanie wygasło lub zostało już wykorzystane. Wybierz usługę i termin ponownie.');
        }
        $wejscie = ['id_uslugi' => (string) $oferta['id_uslugi'],
            'id_pracownika' => (string) $oferta['id_pracownika'],
            'data' => $oferta['data'], 'godzina' => $oferta['godzina']];
        $uwagi = wyborTekst($_POST, 'uwagi');
        if (strlen($uwagi) > 4000 || preg_match('//u', $uwagi) !== 1
            || preg_match_all('/./us', $uwagi) > 1000) {
            throw new RuntimeException('Uwagi mogą mieć maksymalnie 1000 znaków.');
        }
        // Jednorazowe potwierdzenie: po błędzie pokażemy nowe podsumowanie.
        unset($_SESSION['wybor_oferty'][$token]);
        $numer = wyborZapisz($pdo, $klient, $oferta, $uwagi);
        $_SESSION['wybor_sukces'] = 'Zapisano rezerwację nr ' . $numer . '. Status: oczekująca.';
        przekieruj('/klient/rezerwuj.php?id_uslugi=' . $oferta['id_uslugi']);
        exit;
    } catch (PDOException $wyjatek) {
        error_log('Rezerwacja: zapis, SQLSTATE ' . $wyjatek->getCode());
        $bladZapisu = 'Nie udało się zapisać wizyty. Termin lub dane mogły się zmienić. Sprawdź ponownie podsumowanie przed potwierdzeniem.';
    } catch (RuntimeException $wyjatek) {
        $bladZapisu = $wyjatek->getMessage();
    }
}

$idUslugi = wyborId($wejscie['id_uslugi'] ?? null);
$idPracownika = wyborId($wejscie['id_pracownika'] ?? null);
$czyWybor = isset($wejscie['id_pracownika']);
$data = wyborTekst($wejscie, 'data');
$godzina = wyborTekst($wejscie, 'godzina');
$godziny = [];
$termin = null;
$bladTerminu = '';
$tokenPodsumowania = '';
$usluga = null;
$pracownicy = [];
$wybrany = null;
$blad = '';
$bladWyboru = '';

if (!$idUslugi) {
    http_response_code(400);
    $blad = 'Nieprawidłowy numer usługi. Wybierz usługę z oferty.';
} else {
    try {
        $zapytanie = $pdo->prepare(
            'SELECT u.id_uslugi, u.id_salonu, u.nazwa, u.cena, u.czas_trwania,
                    s.nazwa AS salon, s.miasto
             FROM uslugi u
             JOIN salony s ON s.id_salonu = u.id_salonu
             JOIN kategorie_uslug k ON k.id = u.id_kategoria
             WHERE u.id_uslugi = ? AND u.aktywnosc = 1
               AND s.aktywnosc = 1 AND k.aktywny = 1'
        );
        $zapytanie->execute([$idUslugi]);
        $usluga = $zapytanie->fetch(PDO::FETCH_ASSOC);

        if (!$usluga) {
            http_response_code(404);
            $blad = 'Ta usługa nie istnieje lub nie jest obecnie dostępna.';
        } else {
            // Nazwiska pochodzą z kont. Profil musi być przypisany do
            // wybranej usługi oraz tego samego salonu co usługa.
            $zapytanie = $pdo->prepare(
                "SELECT DISTINCT p.id_pracownika, p.opis, u.imie, u.nazwisko
                 FROM pracownicy_uslugi pu
                 JOIN pracownicy p ON p.id_pracownika = pu.id_pracownika
                 JOIN uzytkownicy u ON u.id_uzytkownika = p.id_uzytkownika
                 WHERE pu.id_uslugi = ? AND p.id_salonu = ?
                   AND p.aktywnosc = 1 AND u.aktywny = 1
                   AND u.rola = 'pracownik'
                 ORDER BY u.nazwisko, u.imie, p.id_pracownika"
            );
            $zapytanie->execute([$idUslugi, $usluga['id_salonu']]);
            $pracownicy = $zapytanie->fetchAll(PDO::FETCH_ASSOC);

            // Nie ufamy ID z adresu: sprawdzamy je w aktualnej liście
            // uprawnionych pracowników, również po wysłaniu formularza.
            if ($czyWybor) {
                foreach ($pracownicy as $osoba) {
                    if ((int) $osoba['id_pracownika'] === $idPracownika) {
                        $wybrany = $osoba;
                        break;
                    }
                }
                if (!$wybrany) {
                    http_response_code(400);
                    $bladWyboru = 'Wybierz pracownika z poniższej listy. Wskazana osoba nie jest dostępna dla tej usługi w tym salonie.';
                }
            }
        }
    } catch (PDOException $wyjatek) {
        http_response_code(503);
        error_log('Wybór pracownika: SQLSTATE ' . $wyjatek->getCode());
        $blad = 'Nie udało się pobrać danych. Spróbuj ponownie za chwilę.';
        $wybrany = null;
    }
}

if (!$blad && $wybrany && $data !== '') {
    try {
        if (!rezerwacjaData($data) || $data < rezerwacjaTeraz()->format('Y-m-d')) {
            throw new RuntimeException('Wybierz poprawną datę, nie wcześniejszą niż dzisiaj.');
        }
        // Ta sama cena i długość są użyte do obliczeń i podsumowania.
        $aktualna = rezerwacjaUslugaPracownika($pdo, $idPracownika, $idUslugi);
        if ((int) $aktualna['id_salonu'] !== (int) $usluga['id_salonu']) {
            throw new RuntimeException('Salon usługi zmienił się. Odśwież stronę.');
        }
        $usluga['cena'] = $aktualna['cena'];
        $usluga['czas_trwania'] = $aktualna['czas_trwania'];
        $godziny = rezerwacjaWyliczGodziny($data, (int) $usluga['czas_trwania'],
            rezerwacjaGodzinyPracy($pdo, $idPracownika, $data),
            rezerwacjaWizytyDnia($pdo, $idPracownika, $data),
            rezerwacjaCzyUrlop($pdo, $idPracownika, $data));
        if ($godzina !== '') {
            foreach ($godziny as $wolny) {
                if ($wolny['godzina'] === $godzina) { $termin = $wolny; break; }
            }
            if (!$termin) {
                throw new RuntimeException('Ta godzina nie jest dostępna. Wybierz inny termin.');
            }
            while (count($_SESSION['wybor_oferty']) >= 10) {
                array_shift($_SESSION['wybor_oferty']);
            }
            $tokenPodsumowania = bin2hex(random_bytes(32));
            $_SESSION['wybor_oferty'][$tokenPodsumowania] = [
                'id_klienta' => (int) $klient['id_uzytkownika'], 'id_uslugi' => $idUslugi,
                'id_pracownika' => $idPracownika, 'id_salonu' => (int) $usluga['id_salonu'],
                'data' => $data, 'godzina' => $godzina, 'cena' => (string) $usluga['cena'],
                'czas_trwania' => (int) $usluga['czas_trwania'], 'wygasa' => time() + 1800
            ];
        }
    } catch (PDOException $wyjatek) {
        error_log('Rezerwacja: terminy, SQLSTATE ' . $wyjatek->getCode());
        $bladTerminu = 'Nie udało się sprawdzić terminów. Spróbuj ponownie.';
        $termin = null;
        $godziny = [];
    } catch (RuntimeException | InvalidArgumentException $wyjatek) {
        $bladTerminu = $wyjatek->getMessage();
        $termin = null;
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rezerwacja wizyty — Reserve Beauty</title>
    <link rel="stylesheet" href="css/klient.css">
</head>
<body>
    <header>
        <nav aria-label="Menu główne">
            <a class="logo" href="<?= e(BASE_URL) ?>/index.php">Reserve Beauty</a>
            <ul>
                <li><a href="<?= e(BASE_URL) ?>/oferta.php">Oferta</a></li>
                <li><a href="<?= e(BASE_URL) ?>/klient/index.php">Panel klienta</a></li>
                <li><a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a></li>
            </ul>
        </nav>
    </header>
    <main>
        <p class="etapy">Usługa → Pracownik → Data → Godzina → Potwierdzenie</p>
        <?php if ($sukces): ?><p class="sukces" role="status"><?= e($sukces) ?></p><?php endif; ?>
        <?php if ($bladZapisu): ?><p class="blad" role="alert"><?= e($bladZapisu) ?></p><?php endif; ?>
        <?php if ($blad): ?>
            <section class="karta">
                <h1>Nie można rozpocząć rezerwacji</h1>
                <p class="blad" role="alert"><?= e($blad) ?></p>
                <a class="przycisk" href="<?= e(BASE_URL) ?>/oferta.php">Wróć do oferty</a>
            </section>
        <?php else: ?>
            <a href="<?= e(BASE_URL) ?>/usluga.php?id=<?= $idUslugi ?>">← Wróć do szczegółów usługi</a>
            <section class="karta">
                <h1>Wybierz pracownika</h1>
                <h2><?= e($usluga['nazwa']) ?></h2>
                <div class="podsumowanie">
                    <p><?= e($usluga['salon'] . ' — ' . $usluga['miasto']) ?></p>
                    <p><strong><?= e(number_format((float) $usluga['cena'], 2, ',', ' ')) ?> zł</strong></p>
                    <p><?= (int) $usluga['czas_trwania'] ?> min</p>
                </div>
                <?php if ($bladWyboru): ?><p class="blad" role="alert"><?= e($bladWyboru) ?></p><?php endif; ?>
                <?php if (!$pracownicy): ?>
                    <p role="status">Obecnie nie ma dostępnych pracowników wykonujących tę usługę.</p>
                    <a class="przycisk" href="<?= e(BASE_URL) ?>/oferta.php">Wybierz inną usługę</a>
                <?php else: ?>
                    <!-- GET służy tylko do wyboru, nie zmienia danych w bazie. -->
                    <form method="get" action="<?= e(BASE_URL) ?>/klient/rezerwuj.php">
                        <input type="hidden" name="id_uslugi" value="<?= $idUslugi ?>">
                        <fieldset>
                            <legend>Kto ma wykonać usługę?</legend>
                            <?php foreach ($pracownicy as $osoba): ?>
                                <label class="osoba">
                                    <input type="radio" name="id_pracownika" value="<?= (int) $osoba['id_pracownika'] ?>" required <?= $wybrany && (int) $osoba['id_pracownika'] === $idPracownika ? 'checked' : '' ?>>
                                    <span>
                                        <strong><?= e($osoba['imie'] . ' ' . $osoba['nazwisko']) ?></strong>
                                        <?php if (trim((string) $osoba['opis']) !== ''): ?>
                                            <span class="opis"><?= e($osoba['opis']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <button type="submit">Wybierz pracownika</button>
                    </form>
                    <?php if ($wybrany): ?>
                        <div class="termin">
                            <h2>Wybierz datę</h2>
                            <p>Pracownik: <strong><?= e($wybrany['imie'] . ' ' . $wybrany['nazwisko']) ?></strong></p>
                            <form method="get" action="<?= e(BASE_URL) ?>/klient/rezerwuj.php">
                                <input type="hidden" name="id_uslugi" value="<?= $idUslugi ?>">
                                <input type="hidden" name="id_pracownika" value="<?= $idPracownika ?>">
                                <label>Data wizyty<input type="date" name="data" required min="<?= e(rezerwacjaTeraz()->format('Y-m-d')) ?>" max="9999-12-30" value="<?= e($data) ?>"></label>
                                <button type="submit">Pokaż wolne godziny</button>
                            </form>
                        </div>
                        <?php if ($bladTerminu): ?><p class="blad" role="alert"><?= e($bladTerminu) ?></p><?php endif; ?>
                        <?php if ($godziny): ?>
                            <form class="termin" method="get" action="<?= e(BASE_URL) ?>/klient/rezerwuj.php#podsumowanie">
                                <h2>Wybierz godzinę</h2>
                                <input type="hidden" name="id_uslugi" value="<?= $idUslugi ?>">
                                <input type="hidden" name="id_pracownika" value="<?= $idPracownika ?>">
                                <input type="hidden" name="data" value="<?= e($data) ?>">
                                <label>Godzina rozpoczęcia<select name="godzina" required>
                                    <option value="">Wybierz godzinę</option>
                                    <?php foreach ($godziny as $wolny): ?>
                                        <option value="<?= e($wolny['godzina']) ?>" <?= $godzina === $wolny['godzina'] ? 'selected' : '' ?>><?= e($wolny['godzina']) ?></option>
                                    <?php endforeach; ?>
                                </select></label>
                                <button type="submit">Przejdź do podsumowania</button>
                            </form>
                        <?php elseif ($data !== '' && !$bladTerminu): ?>
                            <p>Brak wolnych godzin w tym dniu. Wybierz inną datę.</p>
                        <?php endif; ?>
                        <?php if ($termin && $tokenPodsumowania !== ''): ?>
                            <section class="termin" id="podsumowanie">
                                <h2>Podsumowanie wizyty</h2>
                                <dl class="szczegoly">
                                    <dt>Usługa</dt><dd><?= e($usluga['nazwa']) ?></dd>
                                    <dt>Salon</dt><dd><?= e($usluga['salon'] . ' — ' . $usluga['miasto']) ?></dd>
                                    <dt>Pracownik</dt><dd><?= e($wybrany['imie'] . ' ' . $wybrany['nazwisko']) ?></dd>
                                    <dt>Data</dt><dd><?= e(rezerwacjaData($data)->format('d.m.Y')) ?></dd>
                                    <dt>Początek</dt><dd><?= e($termin['godzina']) ?></dd>
                                    <dt>Koniec</dt><dd><?= e(substr($termin['data_zakonczenia'], 11, 5)) ?></dd>
                                    <dt>Czas trwania</dt><dd><?= (int) $usluga['czas_trwania'] ?> min</dd>
                                    <dt>Cena</dt><dd><?= e(number_format((float) $usluga['cena'], 2, ',', ' ')) ?> zł</dd>
                                </dl>
                                <form method="post" action="<?= e(BASE_URL) ?>/klient/rezerwuj.php#podsumowanie">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="oferta_token" value="<?= e($tokenPodsumowania) ?>">
                                    <label>Uwagi (opcjonalne, do 1000 znaków)<textarea name="uwagi" maxlength="1000"><?= e($uwagi) ?></textarea></label>
                                    <p class="pomoc">Godziny podajemy w czasie polskim. Termin zostanie zajęty dopiero po zapisaniu rezerwacji. Jeżeli cena lub czas usługi się zmienią, poprosimy o ponowne potwierdzenie.</p>
                                    <button type="submit">Potwierdzam rezerwację</button>
                                </form>
                            </section>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
