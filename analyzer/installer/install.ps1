# VVA Analyzer: Python + AI libraries for the analysis agent.
# Run by the installer (VVA-Analyzer-Setup.exe); can also be run by hand:
#   powershell -ExecutionPolicy Bypass -File install.ps1 -AppDir "C:\...\VVA Analyzer"
param(
    [string]$AppDir = $PSScriptRoot,
    [switch]$Cpu,        # force the processor version of PyTorch
    [switch]$Gpu,        # force the NVIDIA (CUDA) version, even if no card is detected
    [switch]$StartTray   # start VVA Analyzer when done (used by "Przeinstaluj z GPU" in the tray)
)
# 'Continue': Windows PowerShell 5.1 turns any stderr line of python/pip (warnings, a
# "Traceback" from a probe) into a fatal error under 'Stop'. Native commands are checked
# through $LASTEXITCODE instead; cmdlets that must succeed use -ErrorAction Stop.
$ErrorActionPreference = 'Continue'
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.WindowTitle = 'VVA Analyzer: instalacja'
Start-Transcript -Path (Join-Path $AppDir 'install.log') -Append | Out-Null

function Step($text) { Write-Host ""; Write-Host "==> $text" -ForegroundColor Cyan }

function Test-NvidiaGpu {
    # 1) nvidia-smi (on PATH, in System32 or the old NVSMI folder) works once the driver is installed
    $smi = @('nvidia-smi', "$env:SystemRoot\System32\nvidia-smi.exe", "$env:ProgramFiles\NVIDIA Corporation\NVSMI\nvidia-smi.exe")
    foreach ($s in $smi) {
        try {
            $out = & $s -L 2>$null
            if ($LASTEXITCODE -eq 0 -and "$out" -match 'GPU') { Write-Host "Karta: $out"; return $true }
        } catch {}
    }
    # 2) the list of graphics cards in Windows (works even while the driver is being updated)
    try {
        $cards = Get-CimInstance Win32_VideoController | Where-Object { $_.Name -match 'NVIDIA' }
        if ($cards) { Write-Host "Karta: $($cards[0].Name)"; return $true }
    } catch {}
    return $false
}

function Install-Torch($vpy, [bool]$useGpu) {
    $current = & $vpy -c "import torch; print('cuda' if torch.version.cuda else 'cpu')" 2>$null
    $current = if ($LASTEXITCODE -eq 0) { "$current".Trim() } else { '' }
    $want = if ($useGpu) { 'cuda' } else { 'cpu' }
    if ($current -eq $want) { Write-Host "PyTorch ($want) już zainstalowany."; return }
    if ($current) {
        Step "Wymieniam PyTorch ($current) na wersję $want"
        & $vpy -m pip uninstall -y torch torchvision
    }
    if ($useGpu) {
        Step 'Instaluję PyTorch dla karty NVIDIA (ok. 3 GB, to potrwa kilka minut)'
        Test-FreeSpace 7   # 2.5 GB download + ~4.5 GB installed
        foreach ($cu in @('cu126', 'cu128', 'cu124')) {
            # --no-cache-dir: the 2.5 GB wheel is not kept a second time in pip's cache
            & $vpy -m pip install torch torchvision --no-cache-dir --resume-retries 20 --index-url "https://download.pytorch.org/whl/$cu"
            if ($LASTEXITCODE -eq 0) { return }
            Write-Host "Wersja $cu nie zainstalowała się (sieć albo miejsce na dysku), próbuję kolejnej..." -ForegroundColor Yellow
            & $vpy -m pip cache purge 2>$null | Out-Null
            Test-FreeSpace 7
        }
        throw 'Instalacja PyTorch (CUDA) nie powiodła się.'
    }
    Step 'Instaluję PyTorch na procesor (analiza będzie wolniejsza)'
    & $vpy -m pip install torch torchvision --no-cache-dir --resume-retries 20 --index-url https://download.pytorch.org/whl/cpu
    if ($LASTEXITCODE -ne 0) { throw 'Instalacja PyTorch nie powiodła się.' }
}

function Test-FreeSpace([double]$needGB) {
    $drive = (Get-Item $env:LOCALAPPDATA).PSDrive
    $free = [math]::Round($drive.Free / 1GB, 1)
    if ($free -lt $needGB) {
        throw "Za mało miejsca na dysku $($drive.Name): wolne $free GB, potrzeba około $needGB GB. Zwolnij miejsce (np. Kosz, Pobrane, Oczyszczanie dysku) i uruchom instalator ponownie."
    }
    Write-Host "Wolne miejsce na dysku $($drive.Name): $free GB"
}

function Find-Python312 {
    try {
        $p = & py -3.12 -c "import sys; print(sys.executable)" 2>$null
        if ($LASTEXITCODE -eq 0 -and $p) { return $p.Trim() }
    } catch {}
    foreach ($c in @("$env:LOCALAPPDATA\Programs\Python\Python312\python.exe", "$env:ProgramFiles\Python312\python.exe")) {
        if (Test-Path $c) { return $c }
    }
    return $null
}

try {
    # a running VVA Analyzer keeps PyTorch files open: close it first
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object { $_.ExecutablePath -and $_.ExecutablePath.StartsWith((Join-Path $AppDir 'venv'), 'OrdinalIgnoreCase') } |
        ForEach-Object { Write-Host "Zamykam działający VVA Analyzer (PID $($_.ProcessId))"; Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

    Step 'Sprawdzam miejsce na dysku'
    $pipCache = Join-Path $env:LOCALAPPDATA 'pip\cache'
    if (Test-Path $pipCache) {
        Write-Host 'Usuwam pamięć podręczną pip z poprzednich prób...'
        Remove-Item -Recurse -Force $pipCache -ErrorAction SilentlyContinue
    }
    Test-FreeSpace 2

    Step 'Szukam Pythona 3.12'
    $py = Find-Python312
    if (-not $py) {
        Step 'Pobieram i instaluję Pythona 3.12 (tylko dla tego użytkownika)'
        $setup = Join-Path $env:TEMP 'python-3.12.10-amd64.exe'
        Invoke-WebRequest 'https://www.python.org/ftp/python/3.12.10/python-3.12.10-amd64.exe' -OutFile $setup -ErrorAction Stop
        Start-Process $setup -ArgumentList '/quiet InstallAllUsers=0 Include_launcher=1 PrependPath=0 Include_test=0' -Wait
        $py = Find-Python312
        if (-not $py) { throw 'Instalacja Pythona 3.12 nie powiodła się.' }
    }
    Write-Host "Python: $py"

    $venv = Join-Path $AppDir 'venv'
    $vpy = Join-Path $venv 'Scripts\python.exe'
    if (Test-Path $vpy) {
        & $vpy -c "import sys, pip" 2>$null
        if ($LASTEXITCODE -ne 0) {
            Write-Host 'Środowisko z poprzedniej instalacji jest uszkodzone: tworzę je od nowa.' -ForegroundColor Yellow
            Remove-Item -Recurse -Force $venv -ErrorAction SilentlyContinue
        }
    }
    if (-not (Test-Path $vpy)) {
        Step 'Tworzę środowisko programu'
        & $py -m venv $venv
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path $vpy)) { throw 'Nie udało się utworzyć środowiska Pythona.' }
    }
    & $vpy -m pip install --upgrade pip --quiet

    Step 'Sprawdzam kartę graficzną'
    $gpu = if ($Gpu) { $true } elseif ($Cpu) { $false } else { Test-NvidiaGpu }
    if (-not $gpu) { Write-Host 'Nie znaleziono karty NVIDIA.' -ForegroundColor Yellow }
    Install-Torch $vpy $gpu

    Step 'Instaluję VVA Analyzer'
    & $vpy -m pip install --upgrade --no-cache-dir "$(Join-Path $AppDir 'app')[vision,tray]"
    if ($LASTEXITCODE -ne 0) { throw 'Instalacja VVA Analyzer nie powiodła się.' }

    Step 'Instaluję śledzenie zawodników (BoT-SORT + re-ID)'
    & $vpy -m pip install --upgrade --no-cache-dir "$(Join-Path $AppDir 'app')[tracking]"
    if ($LASTEXITCODE -ne 0) { Write-Host 'Śledzenie BoT-SORT pominięte: zostaje prostszy tracker.' -ForegroundColor Yellow }

    Step 'Pobieram modele AI (wykrywanie piłki i odczyt wyniku)'
    $data = Join-Path $env:APPDATA 'VVA Analyzer'
    New-Item -ItemType Directory -Force -Path $data | Out-Null
    Push-Location $data
    & $vpy -c "from ultralytics import YOLO; YOLO('yolo11s.pt'); import easyocr; easyocr.Reader(['en'], gpu=False, verbose=False); import torch; print('GPU:', torch.cuda.is_available())"
    if ($LASTEXITCODE -ne 0) { Write-Host 'Modele pobiorą się przy pierwszej analizie.' -ForegroundColor Yellow }
    Pop-Location

    Step 'Gotowe!'
    Write-Host 'VVA Analyzer uruchomi się za chwilę (ikona przy zegarze).' -ForegroundColor Green
    if ($gpu) { Write-Host 'Jeśli powyżej jest "GPU: False", zainstaluj/aktualizuj sterownik NVIDIA i uruchom ponownie komputer.' }
    if ($StartTray) {
        Start-Process (Join-Path $venv 'Scripts\pythonw.exe') -ArgumentList '-m vva_analyzer tray' -WorkingDirectory $data
    }
    Start-Sleep -Seconds 3
    Stop-Transcript | Out-Null
    exit 0
} catch {
    Write-Host ""
    Write-Host "BŁĄD: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host ($_ | Out-String)
    Write-Host $_.ScriptStackTrace
    Write-Host "Log: $(Join-Path $AppDir 'install.log')"
    Stop-Transcript | Out-Null
    Read-Host 'Naciśnij Enter, aby zamknąć'
    exit 1
}
