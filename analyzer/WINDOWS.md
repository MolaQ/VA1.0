# VVA Analyzer na Windows (agent analizy)

Program działa w tle na komputerze z kartą graficzną NVIDIA (np. RTX 3060) i ma ikonę przy zegarze.
Sam pobiera z VVA mecze czekające na analizę, liczy je na karcie i odsyła wyniki. Trener widzi je
w VVA na żywo. Każdy klub instaluje swojego agenta z tokenem swojego klubu.

Kolory ikony: zielona – czeka na mecze, niebieska – analizuje, pomarańczowa – brak ustawień
lub kalibracji, szara – brak połączenia z VVA, czerwona – błąd (szczegóły w „Pokaż log”).

## Instalacja

1. Pobierz instalator: GitHub → repozytorium → **Actions** → **analyzer-installer** → najnowsze
   uruchomienie → **Artifacts** → `VVA-Analyzer-Setup` (plik .zip z instalatorem .exe).
2. Uruchom `VVA-Analyzer-Setup-x.y.z.exe`. Nie potrzeba uprawnień administratora.
   Windows może pokazać „System Windows ochronił komputer”: kliknij **Więcej informacji → Uruchom mimo to**
   (instalator nie jest podpisany certyfikatem).
3. Zaznacz „Uruchamiaj razem z Windows”. Instalator otworzy czarne okno i sam zainstaluje Pythona
   oraz biblioteki AI (ok. 3–4 GB, 5–20 minut). Okno zamknie się samo.
4. Przy pierwszym uruchomieniu pojawi się okno **Ustawienia**:
   - adres VVA, taki jak w przeglądarce, np. `http://localhost/molak/VVA/public`
     (na serwerze domowym: jego adres w sieci, np. `http://192.168.1.20`),
   - token: w VVA **Klub → Integracje AI → Utwórz token** i skopiuj go przyciskiem „Kopiuj”.
5. Prawy klik na ikonę → **Kalibruj kamerę…**, wklej link do dowolnego meczu z tej kamery,
   popraw linie boiska i zaznacz oba pola wyniku (jak przy `calibrate`). Kalibrację robisz raz,
   dopóki kamera stoi w tym samym miejscu.

Od teraz każdy nowy mecz z linkiem YouTube trafia do kolejki, a agent sam go analizuje.
W VVA na stronie **Integracje AI** widać, czy agent działa.

## Menu ikony

- **Otwórz VVA** – strona w przeglądarce,
- **Sprawdź teraz** – nie czekaj 20 s na kolejne sprawdzenie kolejki,
- **Kalibruj kamerę…**, **Ustawienia…**, **Pokaż log**, **Zakończ**.

Pliki programu: `%LOCALAPPDATA%\Programs\VVA Analyzer`. Ustawienia, kalibracja, log i pobrane
nagrania: `%APPDATA%\VVA Analyzer` (nagranie jest kasowane po udanej analizie, chyba że
w ustawieniach zaznaczysz „Zachowuj pobrane nagrania”).

## Bez instalatora (dla programisty)

```
cd analyzer
python -m pip install -e ".[vision,tray]"
python -m vva_analyzer tray      # ikona w zasobniku
python -m vva_analyzer agent --server http://localhost/molak/VVA/public --token "1|..."   # bez okna
```
