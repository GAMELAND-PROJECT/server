param(
    [string]$ClientRoot = "D:\Allclient",
    [string]$ServerRoot = "F:\SV GAMELAND\server",
    [switch]$RefreshAMXX
)

$ErrorActionPreference = "Stop"

$cstrike = Join-Path $ClientRoot "cstrike"
$addons = Join-Path $cstrike "addons"
$metamodPlugins = Join-Path $addons "metamod\plugins.ini"
$sourceSma = Join-Path $ServerRoot "cstrike\addons\amxmodx\scripting\backweapons.sma"
$optimizerSma = Join-Path $ServerRoot "cstrike\addons\amxmodx\scripting\gameland_lan_optimizer.sma"
$lanHostCfg = Join-Path $ServerRoot "cstrike\gameland_lan_host.cfg"
$sourceModel = Join-Path $ServerRoot "cstrike\models\backweapons.mdl"
$compiler = Join-Path $ServerRoot "cstrike\addons\amxmodx\scripting\amxxpc.exe"
$includeDir = Join-Path $ServerRoot "cstrike\addons\amxmodx\scripting\include"

if (!(Test-Path -LiteralPath $cstrike)) { throw "Missing cstrike folder: $cstrike" }
if (!(Test-Path -LiteralPath $metamodPlugins)) { throw "Missing Metamod plugins.ini: $metamodPlugins" }
if (!(Test-Path -LiteralPath $sourceSma)) { throw "Missing source: $sourceSma" }
if (!(Test-Path -LiteralPath $optimizerSma)) { throw "Missing source: $optimizerSma" }
if (!(Test-Path -LiteralPath $lanHostCfg)) { throw "Missing config: $lanHostCfg" }
if (!(Test-Path -LiteralPath $sourceModel)) { throw "Missing model: $sourceModel" }
if (!(Test-Path -LiteralPath $compiler)) { throw "Missing AMXX compiler: $compiler" }

$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backupRoot = Join-Path $ClientRoot "backups\backweapons-lan-$stamp"
New-Item -ItemType Directory -Force -Path $backupRoot | Out-Null

$pathsToBackup = @(
    (Join-Path $addons "amxmodx"),
    $metamodPlugins,
    (Join-Path $cstrike "models\backweapons.mdl"),
    (Join-Path $cstrike "gameland_lan_host.cfg"),
    (Join-Path $cstrike "userconfig.cfg")
)

foreach ($path in $pathsToBackup) {
    if (Test-Path -LiteralPath $path) {
        $name = Split-Path -Leaf $path
        Copy-Item -LiteralPath $path -Destination (Join-Path $backupRoot $name) -Recurse -Force
    }
}

New-Item -ItemType Directory -Force -Path $addons | Out-Null

$downloadRoot = Join-Path $env:TEMP "gameland-amxx-$stamp"
New-Item -ItemType Directory -Force -Path $downloadRoot | Out-Null
$targetAmxxDll = Join-Path $addons "amxmodx\dlls\amxmodx_mm.dll"
if ($RefreshAMXX -or !(Test-Path -LiteralPath $targetAmxxDll)) {
    $packages = @(
        @{
            Name = "amxmodx-base-windows.zip"
            Url = "https://www.amxmodx.org/amxxdrop/1.9/amxmodx-1.9.0-git5303-base-windows.zip"
        },
        @{
            Name = "amxmodx-cstrike-windows.zip"
            Url = "https://www.amxmodx.org/amxxdrop/1.9/amxmodx-1.9.0-git5303-cstrike-windows.zip"
        }
    )

    foreach ($pkg in $packages) {
        $zipPath = Join-Path $downloadRoot $pkg.Name
        Invoke-WebRequest -Uri $pkg.Url -OutFile $zipPath -UseBasicParsing
        Expand-Archive -LiteralPath $zipPath -DestinationPath $downloadRoot -Force
    }

    $packageCstrike = Join-Path $downloadRoot "addons\amxmodx"
    if (!(Test-Path -LiteralPath (Join-Path $packageCstrike "dlls\amxmodx_mm.dll"))) {
        throw "Downloaded AMXX package did not contain amxmodx_mm.dll"
    }

    Copy-Item -LiteralPath $packageCstrike -Destination $addons -Recurse -Force
}

$pluginsDir = Join-Path $addons "amxmodx\plugins"
$configsDir = Join-Path $addons "amxmodx\configs"
$scriptingDir = Join-Path $addons "amxmodx\scripting"
$modelsDir = Join-Path $cstrike "models"
New-Item -ItemType Directory -Force -Path $pluginsDir, $configsDir, $scriptingDir, $modelsDir | Out-Null

$compiled = Join-Path $downloadRoot "backweapons.amxx"
& $compiler $sourceSma "-i$includeDir" "-o$compiled"
if ($LASTEXITCODE -ne 0) { throw "Back Weapons compile failed with exit code $LASTEXITCODE" }
if (!(Test-Path -LiteralPath $compiled)) { throw "Compiled plugin was not created: $compiled" }

$compiledOptimizer = Join-Path $downloadRoot "gameland_lan_optimizer.amxx"
& $compiler $optimizerSma "-i$includeDir" "-o$compiledOptimizer"
if ($LASTEXITCODE -ne 0) { throw "LAN Optimizer compile failed with exit code $LASTEXITCODE" }
if (!(Test-Path -LiteralPath $compiledOptimizer)) { throw "Compiled plugin was not created: $compiledOptimizer" }

Copy-Item -LiteralPath $compiled -Destination (Join-Path $pluginsDir "backweapons.amxx") -Force
Copy-Item -LiteralPath $compiledOptimizer -Destination (Join-Path $pluginsDir "gameland_lan_optimizer.amxx") -Force
Copy-Item -LiteralPath $sourceSma -Destination (Join-Path $scriptingDir "backweapons.sma") -Force
Copy-Item -LiteralPath $optimizerSma -Destination (Join-Path $scriptingDir "gameland_lan_optimizer.sma") -Force
Copy-Item -LiteralPath $sourceModel -Destination (Join-Path $modelsDir "backweapons.mdl") -Force
Copy-Item -LiteralPath $lanHostCfg -Destination (Join-Path $cstrike "gameland_lan_host.cfg") -Force

$userConfig = Join-Path $cstrike "userconfig.cfg"
$userConfigLines = if (Test-Path -LiteralPath $userConfig) {
    Get-Content -LiteralPath $userConfig
} else {
    @()
}
$userConfigLines = @($userConfigLines | Where-Object { $_ -notmatch '^\s*exec\s+("?gameland_lan_host\.cfg"?)\s*$' })
$userConfigLines += ""
$userConfigLines += "exec gameland_lan_host.cfg"
Set-Content -LiteralPath $userConfig -Value $userConfigLines -Encoding ASCII

$pluginConfig = Join-Path $configsDir "plugins.ini"
$pluginLines = @(
    "; GameLand LAN AMX Mod X plugins",
    "; Keep the listen-server plugin set minimal for smooth LAN hosting.",
    "",
    "gameland_lan_optimizer.amxx",
    "backweapons.amxx"
)
Set-Content -LiteralPath $pluginConfig -Value $pluginLines -Encoding ASCII

$moduleConfig = Join-Path $configsDir "modules.ini"
$moduleLines = @(
    "; GameLand LAN AMX Mod X modules",
    "fakemeta",
    "hamsandwich",
    "cstrike"
)
Set-Content -LiteralPath $moduleConfig -Value $moduleLines -Encoding ASCII

$metaLines = Get-Content -LiteralPath $metamodPlugins
$metaLines = @($metaLines | Where-Object {
    $_ -notmatch '^\s*;\s*GameLand LAN AMX Mod X\s*$' -and
    $_ -notmatch 'addons/amxmodx/dlls/amxmodx_mm\.dll' -and
    $_ -notmatch 'addons\\amxmodx\\dlls\\amxmodx_mm\.dll'
})
$cleanMetaLines = New-Object System.Collections.Generic.List[string]
$previousBlank = $false
foreach ($line in $metaLines) {
    $isBlank = [string]::IsNullOrWhiteSpace($line)
    if ($isBlank -and $previousBlank) {
        continue
    }
    $cleanMetaLines.Add($line)
    $previousBlank = $isBlank
}
$metaLines = @($cleanMetaLines)
$metaLines += ""
$metaLines += "; GameLand LAN AMX Mod X"
$metaLines += "win32 addons/amxmodx/dlls/amxmodx_mm.dll"
Set-Content -LiteralPath $metamodPlugins -Value $metaLines -Encoding ASCII

$result = [ordered]@{
    Backup = $backupRoot
    AMXX = Join-Path $addons "amxmodx\dlls\amxmodx_mm.dll"
    Optimizer = Join-Path $pluginsDir "gameland_lan_optimizer.amxx"
    Plugin = Join-Path $pluginsDir "backweapons.amxx"
    Source = Join-Path $scriptingDir "backweapons.sma"
    Model = Join-Path $modelsDir "backweapons.mdl"
    LanHostConfig = Join-Path $cstrike "gameland_lan_host.cfg"
    MetamodConfig = $metamodPlugins
    AMXXConfig = $pluginConfig
}

$result.GetEnumerator() | ForEach-Object { "[OK] {0}: {1}" -f $_.Key, $_.Value }
