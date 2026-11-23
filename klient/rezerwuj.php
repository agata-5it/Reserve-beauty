<?php
// Obsługa również formularza pozostawionego otwartego przed podmianą plików.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require __DIR__ . '/zapisz-rezerwacje.php';
    exit;
}
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

$wejscie = $_GET;
$bladZapisu = $_SESSION['zapis_rezerwacji_blad'] ?? '';
$uwagi = $_SESSION['zapis_rezerwacji_uwagi'] ?? '';
unset($_SESSION['zapis_rezerwacji_blad'], $_SESSION['zapis_rezerwacji_uwagi']);
$sukces = $_SESSION['wybor_sukces'] ?? '';
unset($_SESSION['wybor_sukces']);
$_SESSION['wybor_oferty'] = $_SESSION['wybor_oferty'] ?? [];
// Podsumowania są ważne 30 minut; osobne karty mają osobne tokeny.
foreach ($_SESSION['wybor_oferty'] as $klucz => $stara) {
    if ($stara['wygasa'] < time()) { unset($_SESSION['wybor_oferty'][$klucz]); }
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
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/css/klient.css?v=3">
    <script src="<?= e(BASE_URL) ?>/js/rezerwacje.js?v=3" defer></script>
</head>
<body class="strona-klienta rezerwacja">
    <nav class="menu-klienta" aria-label="Menu klienta">
        <ul>
            <li><a href="<?= e(BASE_URL) ?>/klient/index.php">Podsumowanie</a></li>
            <li><a href="<?= e(BASE_URL) ?>/klient/rezerwacje.php">Moje rezerwacje</a></li>
            <li><a href="<?= e(BASE_URL) ?>/oferta.php" aria-current="page">Oferta usług</a></li>
            <li><a href="<?= e(BASE_URL) ?>/profil.php">Mój profil</a></li>
        </ul>
    </nav>
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
                    <!-- Bez JavaScript ten sam formularz działa przez zwykły GET. -->
                    <form id="wybor-terminu" method="get" action="<?= e(BASE_URL) ?>/klient/rezerwuj.php#podsumowanie"
                          data-terminy-url="<?= e(BASE_URL) ?>/klient/dostepne-terminy.php">
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
                        <section id="kalendarz-terminow" class="kalendarz-klienta" hidden aria-label="Kalendarz dostępnych dni">
                            <div class="kalendarz-nawigacja">
                                <button type="button" id="miesiac-wstecz" aria-label="Poprzedni miesiąc">←</button>
                                <h2 id="miesiac-tytul"></h2>
                                <button type="button" id="miesiac-dalej" aria-label="Następny miesiąc">→</button>
                            </div>
                            <p id="kalendarz-komunikat" role="status" aria-live="polite"></p>
                            <div class="kalendarz-tydzien" aria-hidden="true"><span>Pon</span><span>Wt</span><span>Śr</span><span>Czw</span><span>Pt</span><span>Sob</span><span>Nd</span></div>
                            <div id="kalendarz-dni" class="kalendarz-dni" aria-busy="false"></div>
                            <p class="kalendarz-legenda">Różowe dni: wolne godziny · Szare: niedostępne · Bordowy: wybrany dzień</p>
                            <button type="button" id="kalendarz-ponow" hidden>Spróbuj ponownie</button>
                        </section>
                        <label>Data wizyty
                            <input type="date" name="data" required min="<?= e(rezerwacjaTeraz()->format('Y-m-d')) ?>" max="9999-12-30" value="<?= e($data) ?>">
                        </label>
                        <button type="submit">Pokaż wolne godziny</button>
                        <p class="pomoc">Wybierz pracownika i datę. Kliknięcie godziny otworzy aktualne podsumowanie przed potwierdzeniem.</p>
                        <div class="termin">
                            <h2>Wybierz godzinę</h2>
                            <p id="terminy-komunikat" role="status" aria-live="polite" aria-atomic="true"><?= e($bladTerminu !== '' ? $bladTerminu : ($wybrany && $data !== '' ? ($godziny ? 'Wybierz dostępną godzinę.' : 'Brak wolnych godzin w tym dniu.') : 'Wybierz pracownika i datę.')) ?></p>
                            <div id="terminy-godziny" class="akcje" aria-label="Dostępne godziny" aria-busy="false">
                                <?php foreach ($godziny as $wolny): ?>
                                    <button type="submit" name="godzina" value="<?= e($wolny['godzina']) ?>" aria-label="Wybierz godzinę <?= e($wolny['godzina']) ?>"><?= e($wolny['godzina']) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </form>
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
                            <form method="post" action="<?= e(BASE_URL) ?>/klient/zapisz-rezerwacje.php">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="oferta_token" value="<?= e($tokenPodsumowania) ?>">
                                <label>Uwagi (opcjonalnie, do 1000 znaków)<textarea name="uwagi" maxlength="1000"><?= e($uwagi) ?></textarea></label>
                                <p class="pomoc">Godziny podajemy w czasie polskim. Termin zostanie zajęty dopiero po zapisaniu rezerwacji. Jeżeli cena lub czas usługi się zmienią, poprosimy o ponowne potwierdzenie.</p>
                                <button type="submit">Potwierdzam rezerwację</button>
                            </form>
                        </section>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
