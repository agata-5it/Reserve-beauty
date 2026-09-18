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

**Bieżący etap:** **Etap 1: Projekt, GitHub i baza danych (database.sql, ERD)** (Termin: 25.09.2026 r.)

### Postęp prac według punktów kontrolnych:
- [ ] **Etap 1 (25.09.2026):** Projekt, GitHub i baza danych (database.sql, ERD)
- [ ] **Etap 2 (16.10.2026):** Użytkownicy, rejestracja, logowanie
- [ ] **Etap 3 (06.11.2026):** Panel administracyjny
- [ ] **Etap 4 (20.11.2026):** Klient i rezerwacje (logika konfliktów, panele)
- [ ] **Etap 5 (27.11.2026):** Gotowy projekt i prezentacja


## Zastosowane Technologie

- **Język po stronie serwera:** PHP
- **Baza danych:** MySQL / MariaDB
- **Front-end:** HTML5, CSS3, JavaScript
- **Kontrola wersji:** Git i GitHub
- **Opcjonalne biblioteki / frameworki:** Bootstrap / Tailwind CSS oraz jQuery / FullCalendar

## Instrukcja Uruchomienia Projektu
1. **Sklonuj repozytorium GitHub do /htdocs:**
2. **Uruchomienie Apache w XAMPP**
3. **Otwarcie http://localhost/reserve-beauty/**