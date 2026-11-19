# Reserve-beauty – System Rezerwacji Usług Kosmetycznych

## Autorzy Projektu

- **Maja Dmowska**
- **Agata Gochnio**


## Opis Systemu i Tematyka

Aplikacja dedykowana jest branży beauty. Rozwiązuje problem ręcznego umawiania wizyt, automatyzując proces sprawdzania dostępności kosmetyczek oraz zapobiegając nakładaniu się terminów.

**Główne założeń systemu:**
1. **Obsługa trzech ról:** Klient, Pracownik, Administrator z pełną kontrolą dostępu po stronie serwera.
2. **Kolejność procesu rezerwacji:** Usługa ➔ Pracownik ➔ Data ➔ Godzina ➔ Potwierdzenie.
3. **Zaawansowany algorytm wykrywania konfliktów:** System uwzględnia czas trwania poszczególnych zabiegów, godziny pracy personelu i wyklucza nakładanie się przedziałów czasowych.


## Aktualny Stan Projektu

**Bieżący etap:** **Etap 4: Użytkownicy, rejestracja, logowanie** (Termin: 16.10.2026 r.)

### Postęp prac według punktów kontrolnych:
- [-] **Etap 1 (25.09.2026):** Projekt, GitHub i baza danych (database.sql, ERD)
- [-] **Etap 2 (16.10.2026):** Użytkownicy, rejestracja, logowanie
- [-] **Etap 3 (06.11.2026):** Panel administracyjny
- [-] **Etap 4 (20.11.2026):** Klient i rezerwacje (logika konfliktów, panele)
- [ ] **Etap 5 (27.11.2026):** Gotowy projekt i prezentacja


## Zastosowane Technologie

- **Język po stronie serwera:** PHP
- **Baza danych:** MySQL / MariaDB
- **Front-end:** HTML5, CSS3, JavaScript
- **Kontrola wersji:** Git i GitHub
- **Opcjonalne biblioteki / frameworki:** Bootstrap / Tailwind CSS oraz jQuery / FullCalendar

## Instrukcja Uruchomienia Projektu
1. **Otwarcie https://reserve-beauty.alwaysdata.net/**
2. Aby przetestować panel Administratora:
    email: admin123@gmail.com
    haslo: test
3. Aby przetestować panel Pracownika:
    email: KrystianKor@wp.pl
    haslo: test

## Najważniejsze pliki

| Miejsce | Zadanie |
| --- | --- |
| `oferta.php`, `usluga.php` | Aktywna oferta, filtry i szczegóły usługi |
| `klient/rezerwuj.php` | Wybór pracownika, daty, godziny i podsumowanie |
| `klient/dostepne-terminy.php`, `js/rezerwacje.js` | Pobieranie godzin w JSON i wyświetlanie przycisków |
| `klient/zapisz-rezerwacje.php` | Transakcyjny zapis po ponownej walidacji |
| `klient/rezerwacje.php`, `klient/anuluj-rezerwacje.php` | Własne wizyty klienta i anulowanie |
| `includes/rezerwacje.php` | Wspólne zasady dostępności, statusów i historii |
| `pracownik/index.php`, `pracownik/rezerwacje.php` | Panel i wizyty zalogowanego pracownika |
| `pracownik/zmien-status.php` | Zmiana statusu własnej wizyty |
| `admin/` | Oferta, pracownicy, przypisania, grafik, urlopy, rezerwacje i podsumowanie |

## Zasady rezerwacji

- Usługa, jej kategoria i salon muszą być aktywne. Wybrany pracownik wykonuje tę usługę, należy do jej salonu i ma aktywne konto oraz profil.
- Klient jest ustalany z zalogowanego konta. Cena i czas pochodzą z aktualnej bazy, a nie z zaufania do danych przeglądarki.
- Grafik powtarza się co tydzień: 1 oznacza poniedziałek, 7 niedzielę. Brak grafiku oznacza brak terminów. Wizyta musi mieścić się w godzinach pracy i nie może przecinać przerwy. Stykające się przedziały można połączyć.
- Początki wizyt proponowane są co 15 minut. Nie można rezerwować w przeszłości ani podczas zaplanowanego urlopu. Obie daty urlopu są wliczone; anulowany urlop nie blokuje.
- Konflikt zachodzi, gdy `nowy_poczatek < istniejacy_koniec` oraz `nowy_koniec > istniejacy_poczatek`. Zakończenie jednej wizyty dokładnie w momencie rozpoczęcia kolejnej nie jest konfliktem.
- Oczekująca i potwierdzona wizyta zajmują czas; anulowana zwalnia termin. Kod sprawdzania nakładania zachowawczo uwzględnia wszystkie statusy poza anulowanym.
- Przed zapisem PHP rozpoczyna transakcję i blokuje salon, następnie pracownika. Po blokadzie ponownie sprawdza aktualne warunki i terminy. Operacje zmieniające dostępność muszą respektować wspólną kolejność blokad. Same wyzwalacze nie rozwiązują równoczesnych zapisów.
- Rezerwacja przechowuje własną cenę, początek i koniec. Późniejsza edycja usługi nie zmienia tych danych. Zmiana ceny lub czasu między podsumowaniem a zapisem wymaga ponownego potwierdzenia.


## Rozszerzenia

1. **Urlopy i przerwy połączone z dostępnością** — urlop blokuje rzeczywiste rezerwowanie. W jednym salonie nie mogą nakładać się zaplanowane urlopy dwóch pracowników; różne salony mogą mieć urlopy równocześnie. Urlop nie może kolidować z oczekującą lub potwierdzoną wizytą. Grafik dopuszcza oddzielne przedziały pracy.
2. **Wspólna historia statusów** — działania wszystkich trzech ról trafiają do historii w tej samej transakcji co zmiana rezerwacji.
3. **AJAX dostępnych godzin** — zmiana pracownika lub daty aktualizuje godziny bez przeładowania. Obsługiwane są wczytywanie, brak terminów, błąd oraz pomijanie spóźnionych odpowiedzi. Zmiana wyboru wyłącza stare podsumowanie. Kliknięcie godziny otwiera podsumowanie w PHP; bez JavaScript działa zwykły formularz.

Kalendarz urlopów administratora jest dodatkowym widokiem danych. O dostępności decydują kontrole w PHP, nie sam wygląd kalendarza. Nie deklarujemy automatycznego uwzględniania świąt państwowych, jeśli nie dodano ich do logiki.