(() => {
    'use strict';
    const formularz = document.getElementById('wybor-terminu');
    if (!formularz || !window.fetch || !window.AbortController) return;
    const lista = document.getElementById('terminy-godziny');
    const komunikat = document.getElementById('terminy-komunikat');
    const data = formularz.elements.namedItem('data');
    const usluga = formularz.elements.namedItem('id_uslugi');
    if (!lista || !komunikat || !data || !usluga) return;

    let numerZapytania = 0;
    let kontroler = null;

    function wybor() {
        const pracownik = formularz.querySelector('input[name="id_pracownika"]:checked');
        return { id_uslugi: usluga.value, id_pracownika: pracownik ? pracownik.value : '', data: data.value };
    }

    function wyczyscPodsumowanie() {
        const podsumowanie = document.getElementById('podsumowanie');
        if (podsumowanie) {
            // Nie można potwierdzić starego terminu po zmianie daty/pracownika.
            podsumowanie.hidden = true;
            podsumowanie.querySelectorAll('input, button, textarea').forEach(pole => { pole.disabled = true; });
        }
        lista.replaceChildren();
    }

    async function pobierz() {
        const numer = ++numerZapytania;
        if (kontroler) kontroler.abort();
        wyczyscPodsumowanie();
        lista.setAttribute('aria-busy', 'false');
        komunikat.className = 'pomoc';
        const wybrane = wybor();
        if (!wybrane.id_pracownika || !wybrane.data || !data.checkValidity()) {
            komunikat.textContent = 'Wybierz pracownika i poprawną datę.';
            return;
        }
        const aktualnyKontroler = new AbortController();
        kontroler = aktualnyKontroler;
        let przekroczonoCzas = false;
        const limit = window.setTimeout(() => {
            przekroczonoCzas = true;
            aktualnyKontroler.abort();
        }, 15000);
        lista.setAttribute('aria-busy', 'true');
        komunikat.textContent = 'Wczytywanie terminów…';
        try {
            const url = new URL(formularz.dataset.terminyUrl, window.location.href);
            url.search = new URLSearchParams(wybrane).toString();
            const odpowiedz = await fetch(url, {
                method: 'GET', credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' }, signal: aktualnyKontroler.signal
            });
            if (numer !== numerZapytania) return;
            if (odpowiedz.redirected || !(odpowiedz.headers.get('content-type') || '').includes('application/json')) {
                throw new Error('Nie udało się pobrać terminów. Odśwież stronę i sprawdź, czy jesteś zalogowana lub zalogowany.');
            }
            const wynik = await odpowiedz.json();
            if (numer !== numerZapytania) return;
            if (!odpowiedz.ok || !wynik || wynik.ok !== true) {
                throw new Error(wynik && typeof wynik.komunikat === 'string' ? wynik.komunikat : 'Nie udało się pobrać terminów. Spróbuj ponownie.');
            }
            const obecne = wybor();
            if (obecne.id_pracownika !== wybrane.id_pracownika || obecne.data !== wybrane.data || obecne.id_uslugi !== wybrane.id_uslugi) return;
            if (String(wynik.id_pracownika) !== wybrane.id_pracownika || String(wynik.id_uslugi) !== wybrane.id_uslugi || wynik.data !== wybrane.data
                || !Array.isArray(wynik.godziny) || !wynik.godziny.every(g => typeof g === 'string' && /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(g))) {
                throw new Error('Otrzymano nieprawidłową odpowiedź. Spróbuj ponownie.');
            }
            for (const godzina of wynik.godziny) {
                const przycisk = document.createElement('button');
                przycisk.type = 'submit';
                przycisk.name = 'godzina';
                przycisk.value = godzina;
                przycisk.textContent = godzina;
                przycisk.setAttribute('aria-label', 'Wybierz godzinę ' + godzina);
                lista.append(przycisk);
            }
            komunikat.textContent = wynik.godziny.length ? 'Wybierz dostępną godzinę.' : 'Brak wolnych godzin w tym dniu.';
        } catch (blad) {
            if (numer !== numerZapytania) return;
            komunikat.className = 'blad';
            komunikat.textContent = przekroczonoCzas
                ? 'Pobieranie trwa zbyt długo. Kliknij „Pokaż wolne godziny”, aby spróbować ponownie.'
                : (blad instanceof Error && blad.name !== 'TypeError' ? blad.message : 'Nie udało się pobrać terminów. Sprawdź połączenie i spróbuj ponownie.');
        } finally {
            window.clearTimeout(limit);
            if (numer === numerZapytania) {
                lista.setAttribute('aria-busy', 'false');
                kontroler = null;
            }
        }
    }

    formularz.addEventListener('change', zdarzenie => {
        if (['id_pracownika', 'data'].includes(zdarzenie.target.name)) pobierz();
    });
    formularz.addEventListener('submit', zdarzenie => {
        // Godzina przechodzi zwykłym GET do podsumowania wygenerowanego w PHP.
        // Tam powstaje serwerowy token ceny i czasu. AJAX go nie zastępuje.
        if (zdarzenie.submitter && zdarzenie.submitter.name === 'godzina') return;
        zdarzenie.preventDefault();
        pobierz();
    });
    window.addEventListener('pageshow', zdarzenie => {
        if (zdarzenie.persisted) pobierz(); // Wstecz z pamięci przeglądarki: odśwież godziny.
    });
})();
// Kalendarz pokazuje dostępność; wybór godziny nadal sprawdza istniejący formularz PHP.
(() => {
    'use strict';
    const form = document.getElementById('wybor-terminu');
    const box = document.getElementById('kalendarz-terminow');
    if (!form || !box || !window.fetch || !window.AbortController) return;
    const date = form.elements.namedItem('data');
    const service = form.elements.namedItem('id_uslugi');
    const grid = document.getElementById('kalendarz-dni');
    const title = document.getElementById('miesiac-tytul');
    const message = document.getElementById('kalendarz-komunikat');
    const prev = document.getElementById('miesiac-wstecz');
    const next = document.getElementById('miesiac-dalej');
    const retry = document.getElementById('kalendarz-ponow');
    const months = ['Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec', 'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'];
    let today = date.min;
    let month = (date.value && date.value >= today ? date.value : today).slice(0, 7);
    let sequence = 0;
    let controller = null;
    let internal = false;
    const employee = () => form.querySelector('[name="id_pracownika"]:checked')?.value || '';
    const monthDate = value => new Date(value + '-01T12:00:00Z');
    function selection() {
        grid.querySelectorAll('button').forEach(button => {
            button.setAttribute('aria-pressed', String(button.dataset.date === date.value));
        });
    }
    function clearSelection() {
        date.value = '';
        internal = true;
        date.dispatchEvent(new Event('change', { bubbles: true }));
        internal = false;
    }
    async function load() {
        const current = ++sequence;
        if (controller) controller.abort();
        const person = employee();
        const requestedMonth = month;
        grid.replaceChildren();
        grid.setAttribute('aria-busy', 'false');
        retry.hidden = true;
        message.className = 'pomoc';
        title.textContent = months[Number(month.slice(5)) - 1] + ' ' + month.slice(0, 4);
        prev.disabled = month <= today.slice(0, 7);
        next.disabled = month >= '9999-12';
        if (!person) {
            message.textContent = 'Wybierz pracownika, aby zobaczyć dostępne dni.';
            return;
        }
        const request = new AbortController();
        controller = request;
        const timeout = setTimeout(() => request.abort(), 15000);
        message.textContent = 'Wczytywanie kalendarza…';
        grid.setAttribute('aria-busy', 'true');
        try {
            const url = new URL(form.dataset.terminyUrl, location.href);
            url.search = new URLSearchParams({ id_uslugi: service.value, id_pracownika: person, miesiac: requestedMonth });
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: request.signal });
            if (current !== sequence) return;
            if (response.redirected || !(response.headers.get('content-type') || '').includes('application/json')) {
                throw new Error('Nie udało się wczytać kalendarza. Odśwież stronę i sprawdź logowanie.');
            }
            const result = await response.json();
            if (current !== sequence || person !== employee() || month !== requestedMonth) return;
            if (!response.ok || !result || result.ok !== true) throw new Error(result?.komunikat || 'Nie udało się pobrać kalendarza.');
            const days = new Date(Number(month.slice(0, 4)), Number(month.slice(5)), 0).getDate();
            if (result.miesiac !== month || String(result.id_pracownika) !== person || String(result.id_uslugi) !== service.value
                || !Array.isArray(result.dni) || result.dni.length !== days
                || !result.dni.every((day, i) => day.data === month + '-' + String(i + 1).padStart(2, '0') && Number.isInteger(day.liczba) && day.liczba >= 0)) {
                throw new Error('Nieprawidłowa odpowiedź kalendarza. Spróbuj ponownie.');
            }
            if (typeof result.dzisiaj === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(result.dzisiaj)) {
                today = result.dzisiaj;
                date.min = today;
                prev.disabled = month <= today.slice(0, 7);
            }
            const offset = (monthDate(month).getUTCDay() + 6) % 7;
            for (let i = 0; i < offset; i++) grid.append(document.createElement('span'));
            let available = 0;
            for (const day of result.dni) {
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.date = day.data;
                button.disabled = day.liczba === 0 || day.data < today || day.data > date.max;
                const number = document.createElement('strong');
                number.textContent = String(Number(day.data.slice(8)));
                const label = document.createElement('small');
                label.textContent = button.disabled ? '—' : day.liczba + ' godz.';
                button.append(number, label);
                button.setAttribute('aria-label', day.data + (button.disabled ? ': brak dostępnych godzin' : ': liczba wolnych godzin ' + day.liczba));
                if (day.data === today) button.setAttribute('aria-current', 'date');
                button.addEventListener('click', () => {
                    date.value = day.data;
                    internal = true;
                    date.dispatchEvent(new Event('change', { bubbles: true }));
                    internal = false;
                    selection();
                    document.getElementById('terminy-komunikat')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                });
                grid.append(button);
                if (!button.disabled) available++;
            }
            selection();
            message.textContent = available ? 'Wybierz dzień. Liczba pod datą oznacza wolne godziny rozpoczęcia wizyty.' : 'Brak wolnych terminów w tym miesiącu. Sprawdź kolejny miesiąc.';
        } catch (error) {
            if (current !== sequence) return;
            grid.replaceChildren();
            message.className = 'blad';
            message.textContent = error.name === 'AbortError' ? 'Pobieranie trwa zbyt długo. Spróbuj ponownie.'
                : error.name === 'TypeError' ? 'Błąd połączenia. Spróbuj ponownie.' : error.message;
            retry.hidden = false;
        } finally {
            clearTimeout(timeout);
            if (current === sequence) { grid.setAttribute('aria-busy', 'false'); controller = null; }
        }
    }
    function move(delta) {
        const d = monthDate(month);
        d.setUTCMonth(d.getUTCMonth() + delta);
        const value = d.toISOString().slice(0, 7);
        if (value < today.slice(0, 7) || value > '9999-12') return;
        month = value;
        clearSelection();
        load();
    }
    prev.addEventListener('click', () => move(-1));
    next.addEventListener('click', () => move(1));
    retry.addEventListener('click', load);
    form.addEventListener('change', event => {
        if (internal) return;
        if (event.target.name === 'id_pracownika') {
            clearSelection();
            load();
        } else if (event.target.name === 'data') {
            if (date.value && date.checkValidity()) month = date.value.slice(0, 7);
            load();
        }
    });
    window.addEventListener('pageshow', event => { if (event.persisted) load(); });
    box.hidden = false;
    load();
})();
