# VVA Analyzer: automatyczna analiza wideo (etap 1)

> **Program na Windows z ikoną w zasobniku (agent analizy):** zobacz [WINDOWS.md](WINDOWS.md). Poniżej opis dla programistów i ręczne uruchamianie.

Program uruchamiany na komputerze z kartą NVIDIA. Ogląda nagranie meczu ze stałej kamery,
czyta wynik z nakładki (np. Sportcam) i wysyła do VVA:

- **wyniki setów** i początek każdego seta w wideo,
- **każdą wymianę**: kto serwował, kto wygrał punkt, kiedy w nagraniu,
- **zagrywki naszej drużyny** z oceną: `#` as, `=` błąd, `!` w grze (w tabeli: Serve Sum/Err/Ace i BP w wierszu sumy).

Numery zawodników (kto serwował) dochodzą w etapie 2, pozostałe elementy w etapie 3.
Do tego czasu zagrywki są przypisane do drużyny, a trener może je przypisać w panelu kodowania.

## Jak to działa

1. Pobranie nagrania z YouTube (yt-dlp).
2. Wykrywanie piłki w każdej klatce (YOLO, 12 klatek na sekundę) i odrzucanie piłek leżących przy boisku.
3. Odczyt punktów z nakładki co sekundę (EasyOCR), z odrzucaniem pojedynczych błędnych odczytów.
4. Gwizdek sędziego z dźwięku (koniec wymiany).
5. Łączenie: każda zmiana wyniku to koniec wymiany, a wymianą jest ostatnia akcja z piłką przed zmianą.
   Zagrywa zdobywca poprzedniego punktu, a pierwszą zagrywkę seta rozpoznaje strona boiska (po kalibracji).
6. Wyniki idą do VVA paczkami w trakcie analizy, więc statystyki widać na żywo.

## Instalacja (Windows, laptop z RTX 3060)

Raz, w terminalu (PowerShell):

1. Zainstaluj **Python 3.12**: z instalatorem Pythona wystarczy `py install 3.12`, w przeciwnym razie z python.org
   (zaznacz „Add python.exe to PATH”). Zaktualizuj też sterownik NVIDIA.
2. (Opcjonalnie) **ffmpeg**: program ma własną kopię z pakietu `imageio-ffmpeg`, więc nie musisz nic instalować. Jeśli wolisz systemowy: `winget install Gyan.FFmpeg --source winget` (potem otwórz nowy terminal i sprawdź `ffmpeg -version`).
   Przy błędzie certyfikatu pobierz `ffmpeg-release-essentials.zip` z https://www.gyan.dev/ffmpeg/builds/, rozpakuj do `C:\ffmpeg`
   i dodaj `C:\ffmpeg\bin` do zmiennej PATH.
   Zalecane też **Deno** (YouTube wymaga go do części formatów): `winget install DenoLand.Deno --source winget`.
3. W katalogu projektu:
   ```powershell
   cd analyzer
   py -3.12 -m venv .venv
   .venv\Scripts\Activate.ps1
   python -m pip install --upgrade pip
   python -m pip install torch torchvision --index-url https://download.pytorch.org/whl/cu124
   python -m pip install -e ".[vision,tracking,server,dev]"
   python -c "import torch; print(torch.cuda.is_available())"
   ```
   Po aktywacji linia terminala zaczyna się od `(.venv)`; wszystkie dalsze polecenia wpisuj tylko w takim terminalu.
   Ostatnie polecenie ma wypisać `True` (karta graficzna jest widoczna).
   Jeśli PowerShell blokuje `Activate.ps1`, wpisz raz `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned`.
4. W VVA: **Klub → Integracje AI → utwórz token**. Skopiuj go, pokazuje się tylko raz.

## Użycie

Za każdym razem zaczynasz od aktywacji środowiska: `cd analyzer` i `.venv\Scripts\Activate.ps1`.

**Kalibracja (raz na halę i ustawienie kamery).** Otwiera okno z klatką meczu:

```powershell
python -m vva_analyzer calibrate --url "https://www.youtube.com/watch?v=XCXJATWV9O4" --at 300
```

- Program sam znajduje **linie ataku** (3 m od siatki) i zaznacza ich końce. Popraw przeciągając punkt myszą,
  jeśli trzeba. Napis „MY / US” ma stać na połowie, na której gramy w 1. secie; klawisz **S** przenosi go na drugą
  połowę. **Enter** zatwierdza, **C** czyści punkty, by kliknąć je ręcznie (lewa linia: koniec dalszy, bliższy;
  prawa: bliższy, dalszy).
- Zaznacz prostokątem punkty pierwszej drużyny na nakładce (lewej albo górnej) i Enter, potem drugiej i Enter.
- W terminalu odpowiedz, czy nasza drużyna jest na nakładce pierwsza (`l`), czy druga (`r`).

Powstaje `calibration.json`. `--at 300` to sekunda nagrania, na której widać boisko i wynik.

**Analiza meczu z wysłaniem do VVA.** Numer meczu (`--game`) jest w adresie strony meczu, np. `/games/3`:

```powershell
$env:VVA_API_TOKEN = "wklej-token"
python -m vva_analyzer analyze --url "https://www.youtube.com/watch?v=XCXJATWV9O4" --game 3
```

Przy VVA działającym gdzie indziej niż `localhost:8000` dodaj `--api http://adres/api/v1`.
Bez `--game` analiza tylko zapisuje raport `work\<id>.report.json` (do sprawdzenia przed wysłaniem).

**Poprawki bez ponownego liczenia.** Wykrycia zapisują się w `work\<id>.detections.json`, więc ponowne
uruchomienie (np. po zmianie kalibracji nakładki lub ustawień) trwa sekundy. `--fresh` wymusza pełną analizę.

**Przycisk „Uruchom analizę AI” w VVA.** Zamiast ręcznego `analyze` możesz uruchomić usługę:

```powershell
python -m vva_analyzer serve --token "token-z-VVA" --secret "dowolne-haslo"
```

a w `.env` strony ustawić:

```
VVA_ANALYZER_URL=http://127.0.0.1:8765/jobs
VVA_ANALYZER_TOKEN=dowolne-haslo
```

Potem `php artisan config:clear`. Działać musi też kolejka (`composer dev` ją uruchamia).
Dodanie meczu z linkiem i zaznaczoną analizą AI wyśle go do laptopa, a postęp pokaże się na stronie meczu.

## Pozycje zawodników (boisko 2D pod filmem)

Ten sam przebieg YOLO, który szuka piłki, zwraca też ludzi (klasa COCO „person”), więc pozycje
nic nie kosztują czasowo. Dla każdej osoby punkt stóp (środek dolnej krawędzi ramki) jest
przeliczany homografią z kalibracji boiska na metry (x 0..9 w poprzek, y 0..18 wzdłuż,
0 = linia końcowa bliżej kamery, 9 = siatka). Osoby daleko poza boiskiem (ławka, sędziowie,
widzowie) są odrzucane, a każdy zawodnik dostaje stały identyfikator śladu.

**Śledzenie BoT-SORT + re-ID (pakiet `boxmot`, dodatek `[tracking]`).** Gdy jest zainstalowany,
tracker BoT-SORT działa na każdej analizowanej klatce (12/s): filtr Kalmana przewiduje ruch,
kompensuje ruch kamery i porównuje wygląd ramek małą siecią re-ID (OSNet x0.25). Zawodnicy
mijający się pod siatką nie zamieniają się już śladami, a ślad przetrwa ok. 3 s zasłonięcia.
Bez `boxmot` działa prosty tracker łączący kropki po odległości (`Settings.tracker = "simple"`
wymusza go). Ta sama sieć daje „odcisk” zawodnika (wektor 512 liczb): ślady ze znanym numerem
(OCR, rotacja, poprawka analityka) uczą odcisku numeru, a ślady bez numeru dostają głos na
najbliższy odcisk, gdy jest wyraźnie bliższy od drugiego. Odciski są pamiętane per drużyna w
`work/reid-team<id>.json`, więc każdy mecz i każda poprawka poprawia rozpoznawanie.
Odciski i identyfikatory BoT-SORT trafiają do `*.detections.json`; dla starszych plików uruchom z `--fresh`. Kierunek ciała (trójkącik przy kropce) to kierunek ruchu; stojący zawodnik „patrzy”
na siatkę. Drużyna wynika z połowy boiska i seta (strony zmieniają się co set).

Co 0,5 s powstaje klatka pozycji, wysyłana na końcu analizy do `POST /api/v1/games/{id}/frames`.
Wymaga kalibracji boiska i modelu COCO (własny model tylko-piłka nie daje ludzi).
Numerów koszulek AI jeszcze nie czyta: analityk klika kropkę i wpisuje numer, a numer
zostaje na całym śladzie. Stare pliki `*.detections.json` nie mają pozycji: uruchom z `--fresh`.

## Czas i dokładność

- Szacunkowo 20 do 40 minut na 2 godziny nagrania na RTX 3060 (laptop). `--fps 8` przyspiesza kosztem dokładności.
- Wynik i kolejność serwujących są pewne, o ile nakładka jest czytelna. Asy i błędy zagrywki to w etapie 1
  heurystyka (bardzo krótka wymiana, liczba przejść piłki nad siatką), dlatego mają niższą „pewność”.
- Kamera z boku boiska, mniej więcej na wysokości siatki, daje najlepsze rozpoznanie strony boiska.

Analizuj nagrania własnego klubu albo takie, na które masz zgodę właściciela.

## Testy

```powershell
pytest
```
