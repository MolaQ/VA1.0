# VVA Analyzer: Python + AI libraries for the analysis agent.
# Run by the installer (VVA-Analyzer-Setup.exe); can also be run by hand:
#   powershell -ExecutionPolicy Bypass -File install.ps1 -AppDir "C:\...\VVA Analyzer"
param(
    [string]$AppDir = (Split-Path -Parent $PSScriptRoot),
    [switch]$Cpu
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.WindowTitle = 'VVA Analyzer: instalacja'
Start-Transcript -Path (Join-Path $AppDir 'install.log') -Append | Out-Null

function Step($text) { Write-Host ""; Write-Host "==> $text" -ForegroundColor Cyan }

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
    Step 'Szukam Pythona 3.12'
    $py = Find-Python312
    if (-not $py) {
        Step 'Pobieram i instaluję Pythona 3.12 (tylko dla tego użytkownika)'
        $setup = Join-Path $env:TEMP 'python-3.12.10-amd64.exe'
        Invoke-WebRequest 'https://www.python.org/ftp/python/3.12.10/python-3.12.10-amd64.exe' -OutFile $setup
        Start-Process $setup -ArgumentList '/quiet InstallAllUsers=0 Include_launcher=1 PrependPath=0 Include_test=0' -Wait
        $py = Find-Python312
        if (-not $py) { throw 'Instalacja Pythona 3.12 nie powiodła się.' }
    }
    Write-Host "Python: $py"

    $venv = Join-Path $AppDir 'venv'
    $vpy = Join-Path $venv 'Scripts\python.exe'
    if (-not (Test-Path $vpy)) {
        Step 'Tworzę środowisko programu'
        & $py -m venv $venv
    }
    & $vpy -m pip install --upgrade pip --quiet

    $gpu = $false
    if (-not $Cpu) {
        try { & nvidia-smi -L 2>$null | Out-Null; $gpu = ($LASTEXITCODE -eq 0) } catch { $gpu = $false }
    }
    if ($gpu) {
        Step 'Instaluję PyTorch dla karty NVIDIA (ok. 3 GB, to potrwa kilka minut)'
        & $vpy -m pip install torch torchvision --index-url https://download.pytorch.org/whl/cu124
    } else {
        Step 'Nie wykryto karty NVIDIA: instaluję PyTorch na procesor (analiza będzie wolniejsza)'
        & $vpy -m pip install torch torchvision --index-url https://download.pytorch.org/whl/cpu
    }
    if ($LASTEXITCODE -ne 0) { throw 'Instalacja PyTorch nie powiodła się.' }

    Step 'Instaluję VVA Analyzer'
    & $vpy -m pip install --upgrade "$(Join-Path $AppDir 'app')[vision,tray]"
    if ($LASTEXITCODE -ne 0) { throw 'Instalacja VVA Analyzer nie powiodła się.' }

    Step 'Pobieram modele AI (wykrywanie piłki i odczyt wyniku)'
    $data = Join-Path $env:APPDATA 'VVA Analyzer'
    New-Item -ItemType Directory -Force -Path $data | Out-Null
    Push-Location $data
    & $vpy -c "from ultralytics import YOLO; YOLO('yolo11s.pt'); import easyocr; easyocr.Reader(['en'], gpu=False, verbose=False); import torch; print('GPU:', torch.cuda.is_available())"
    Pop-Location

    Step 'Gotowe!'
    Write-Host 'VVA Analyzer uruchomi się za chwilę (ikona przy zegarze).' -ForegroundColor Green
    Start-Sleep -Seconds 3
    Stop-Transcript | Out-Null
    exit 0
} catch {
    Write-Host ""
    Write-Host "BŁĄD: $_" -ForegroundColor Red
    Write-Host "Log: $(Join-Path $AppDir 'install.log')"
    Stop-Transcript | Out-Null
    Read-Host 'Naciśnij Enter, aby zamknąć'
    exit 1
}
