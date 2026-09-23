param(
    [Parameter(Mandatory = $true)]
    [string]$BackupRoot,
    [string]$ClientRoot = "D:\Allclient"
)

$ErrorActionPreference = "Stop"

if (!(Test-Path -LiteralPath $BackupRoot)) {
    throw "Backup folder not found: $BackupRoot"
}

$cstrike = Join-Path $ClientRoot "cstrike"
$addons = Join-Path $cstrike "addons"

$backupAmxx = Join-Path $BackupRoot "amxmodx"
$backupMetamodPlugins = Join-Path $BackupRoot "plugins.ini"
$backupBackWeaponsModel = Join-Path $BackupRoot "backweapons.mdl"

if (Test-Path -LiteralPath $backupAmxx) {
    $targetAmxx = Join-Path $addons "amxmodx"
    if (Test-Path -LiteralPath $targetAmxx) {
        Remove-Item -LiteralPath $targetAmxx -Recurse -Force
    }
    Copy-Item -LiteralPath $backupAmxx -Destination $targetAmxx -Recurse -Force
    "[OK] Restored AMX Mod X: $targetAmxx"
} else {
    $targetAmxx = Join-Path $addons "amxmodx"
    if (Test-Path -LiteralPath $targetAmxx) {
        Remove-Item -LiteralPath $targetAmxx -Recurse -Force
        "[OK] Removed AMX Mod X because backup did not contain it: $targetAmxx"
    }
}

if (Test-Path -LiteralPath $backupMetamodPlugins) {
    $targetMetamodPlugins = Join-Path $addons "metamod\plugins.ini"
    Copy-Item -LiteralPath $backupMetamodPlugins -Destination $targetMetamodPlugins -Force
    "[OK] Restored Metamod plugins.ini: $targetMetamodPlugins"
}

if (Test-Path -LiteralPath $backupBackWeaponsModel) {
    $targetModel = Join-Path $cstrike "models\backweapons.mdl"
    Copy-Item -LiteralPath $backupBackWeaponsModel -Destination $targetModel -Force
    "[OK] Restored model: $targetModel"
}
