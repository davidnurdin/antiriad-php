# JumPHP's installer for Windows: JumPHP runs in WSL (Linux inside Windows, with Docker Desktop's WSL engine).
#   irm https://jumphp.net/install.ps1 | iex
# Without WSL: says how to install it (an administrator's command, then a restart). With it: install.sh in WSL.
$ErrorActionPreference = 'Stop'
$ref = if ($env:JUMPHP_REF) { $env:JUMPHP_REF } else { 'main' }
$url = "https://raw.githubusercontent.com/davidnurdin/jumPhp/$ref/install.sh"

Write-Host '==> JumPHP for Windows (in WSL)' -ForegroundColor Cyan
$wsl = Get-Command wsl.exe -ErrorAction SilentlyContinue
$distros = if ($wsl) { (wsl.exe --list --quiet 2>$null) -replace "`0", '' | Where-Object { $_.Trim() } } else { @() }
if (-not $wsl -or -not $distros) {
    Write-Host '[!] WSL (Linux in Windows) is not installed. In a PowerShell opened as administrator:' -ForegroundColor Yellow
    Write-Host '      wsl --install'
    Write-Host '    restart Windows, open "Ubuntu" once (it asks a user name), then run this line again.'
    Write-Host '    Docker: Docker Desktop for Windows, with "Use the WSL 2 based engine" (Settings > General).'
    return
}
wsl.exe -e sh -c "curl -fsSL $url | sh"
Write-Host ''
Write-Host 'Then, in Ubuntu (or: wsl in PowerShell): jumphp new my-game' -ForegroundColor Cyan
