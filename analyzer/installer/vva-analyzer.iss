; VVA Analyzer installer (Inno Setup 6, free: https://jrsoftware.org/isinfo.php)
; Built automatically by .github/workflows/analyzer-installer.yml; by hand: iscc vva-analyzer.iss
#define AppVersion "0.2.7"

[Setup]
AppId={{C092ABA3-89AA-4355-90C2-E01CEA11F119}
AppName=VVA Analyzer
AppVersion={#AppVersion}
AppPublisher=VVA
DefaultDirName={localappdata}\Programs\VVA Analyzer
PrivilegesRequired=lowest
DisableProgramGroupPage=yes
OutputDir=Output
OutputBaseFilename=VVA-Analyzer-Setup-{#AppVersion}
SetupIconFile=vva.ico
UninstallDisplayIcon={app}\vva.ico
WizardStyle=modern
Compression=lzma2
SolidCompression=yes
CloseApplications=yes

[Languages]
Name: "polish"; MessagesFile: "compiler:Languages\Polish.isl"
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "autostart"; Description: "Uruchamiaj VVA Analyzer razem z Windows"; GroupDescription: "Dodatkowo:"

[Dirs]
Name: "{userappdata}\VVA Analyzer"

[Files]
Source: "..\vva_analyzer\*.py"; DestDir: "{app}\app\vva_analyzer"; Flags: ignoreversion
Source: "..\pyproject.toml"; DestDir: "{app}\app"; Flags: ignoreversion
Source: "install.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "vva.ico"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{userprograms}\VVA Analyzer"; Filename: "{app}\venv\Scripts\pythonw.exe"; Parameters: "-m vva_analyzer tray"; WorkingDir: "{userappdata}\VVA Analyzer"; IconFilename: "{app}\vva.ico"
Name: "{userstartup}\VVA Analyzer"; Filename: "{app}\venv\Scripts\pythonw.exe"; Parameters: "-m vva_analyzer tray"; WorkingDir: "{userappdata}\VVA Analyzer"; IconFilename: "{app}\vva.ico"; Tasks: autostart

[Run]
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\install.ps1"" -AppDir ""{app}"""; StatusMsg: "Instaluję Pythona i biblioteki AI (kilka GB, 5-20 minut)..."; Flags: waituntilterminated
Filename: "{app}\venv\Scripts\pythonw.exe"; Parameters: "-m vva_analyzer tray"; WorkingDir: "{userappdata}\VVA Analyzer"; Description: "Uruchom VVA Analyzer"; Flags: postinstall nowait skipifsilent

[UninstallDelete]
Type: filesandordirs; Name: "{app}\venv"
Type: filesandordirs; Name: "{app}\app"
Type: files; Name: "{app}\install.log"
