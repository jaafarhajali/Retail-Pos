; Inno Setup script for Retail POS / Retail Production (built by build.ps1, which passes the defines below).
#ifndef Product
  #define Product "CodexyERP"
#endif
#ifndef DisplayName
  #define DisplayName "Codexy ERP"
#endif
#ifndef Version
  #define Version "0.0.0"
#endif
#ifndef DefaultDir
  #define DefaultDir "C:\CodexyERP"
#endif
#ifndef DbName
  #define DbName "retail_pos"
#endif
#ifndef Stage
  #define Stage "stage"
#endif
#ifndef Out
  #define Out "..\..\dist"
#endif

[Setup]
AppId={#Product}
AppName={#DisplayName}
AppVersion={#Version}
AppPublisher=Codexy
DefaultDirName={#DefaultDir}
DisableDirPage=no
DirExistsWarning=no
DisableProgramGroupPage=yes
OutputDir={#Out}
OutputBaseFilename={#Product}-Setup-{#Version}
Compression=lzma2/max
SolidCompression=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
WizardStyle=modern
UninstallDisplayName={#DisplayName}
SetupIconFile={#Stage}\app\public\assets\brand\codexy.ico
UninstallDisplayIcon={app}\app\public\assets\brand\codexy.ico
CloseApplications=no
SetupLogging=yes

[Messages]
SelectDirDesc=Where should {#DisplayName} be installed? A folder without spaces is best. On an existing installation the data stays and the program is updated.

[Files]
Source: "{#Stage}\*"; DestDir: "{app}"; Flags: recursesubdirs createallsubdirs ignoreversion; Excludes: "app\config\app.ini"

[Run]
Filename: "{app}\open.cmd"; Description: "Open {#DisplayName} in the browser"; Flags: postinstall shellexec nowait skipifsilent; Check: InstallWorked

[UninstallRun]
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\post-install.ps1"" -Root ""{app}"" -Product ""{#Product}"" -Uninstall"; Flags: runhidden waituntilterminated; RunOnceId: "services"

[Code]
var
  ShopPage: TInputQueryWizardPage;
  InstallOk: Boolean;

function InstallWorked: Boolean;
begin
  Result := InstallOk;
end;


function IsUpdate: Boolean;
begin
  Result := FileExists(ExpandConstant('{app}\app\config\app.ini'));
end;

procedure InitializeWizard;
begin
  ShopPage := CreateInputQueryPage(wpSelectDir, 'The shop', 'Name and administrator',
    'The shop name goes on receipts (you can change it later in Settings). The administrator signs in as "admin".');
  ShopPage.Add('Shop name:', False);
  ShopPage.Add('Administrator password (8 characters or more):', True);
  ShopPage.Add('The same password again:', True);
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := (PageID = ShopPage.ID) and IsUpdate;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
begin
  Result := True;
  if (CurPageID = ShopPage.ID) and not IsUpdate then begin
    if Length(ShopPage.Values[1]) < 8 then begin
      MsgBox('The administrator password needs at least 8 characters.', mbError, MB_OK);
      Result := False;
    end else if ShopPage.Values[1] <> ShopPage.Values[2] then begin
      MsgBox('The two passwords are not the same.', mbError, MB_OK);
      Result := False;
    end;
  end;
end;

{ A silent install (/VERYSILENT) never shows the page: /ShopName="..." /AdminPassword=... on the command line fill in. }
function GetShop(Param: String): String;
begin
  Result := ShopPage.Values[0];
  if Result = '' then Result := ExpandConstant('{param:ShopName|}');
end;

function GetPass(Param: String): String;
begin
  Result := ShopPage.Values[1];
  if Result = '' then Result := ExpandConstant('{param:AdminPassword|}');
end;

{ After the files are copied: the services, the database and the administrator, by post-install.ps1.
  Its exit code decides; a failure shows the log path instead of pretending. }
procedure CurStepChanged(CurStep: TSetupStep);
var
  Code: Integer;
  Params: String;
begin
  if CurStep = ssPostInstall then begin
    WizardForm.StatusLabel.Caption := 'Installing the database, the web server and the backup service...';
    Params := '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{app}') + '\post-install.ps1" -Root "' + ExpandConstant('{app}') + '" -Product "{#Product}" -DisplayName "{#DisplayName}" -DbName "{#DbName}" -ShopName "' + GetShop('') + '" -AdminPassword "' + GetPass('') + '"';
    InstallOk := Exec('powershell.exe', Params, '', SW_HIDE, ewWaitUntilTerminated, Code) and (Code = 0);
    if not InstallOk then
      MsgBox('The setup could not finish the services. Please send ' + ExpandConstant('{app}') + '\install.log with your question.', mbError, MB_OK);
  end;
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
begin
  if CurUninstallStep = usPostUninstall then begin
    if MsgBox('Also delete the shop''s data and backups in ' + ExpandConstant('{app}') + '? Choose No to keep them for a later installation.', mbConfirmation, MB_YESNO or MB_DEFBUTTON2) = IDYES then begin
      DelTree(ExpandConstant('{app}'), True, True, True);
    end;
  end;
end;
