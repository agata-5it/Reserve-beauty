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