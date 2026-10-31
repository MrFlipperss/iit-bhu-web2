$ErrorActionPreference = 'Stop'
$zipUrl = 'https://windows.php.net/downloads/releases/php-8.2.27-Win32-vs16-x64.zip'
$targetDir = 'C:\Users\Kian\php82'
$zipPath = 'C:\Users\Kian\Downloads\php-8.2.zip'

if (!(Test-Path $targetDir)) {
    New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
}

Write-Host "Downloading PHP 8.2 from $zipUrl..."
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
Invoke-WebRequest -Uri $zipUrl -OutFile $zipPath

Write-Host "Extracting to $targetDir..."
Expand-Archive -Path $zipPath -DestinationPath $targetDir -Force
Remove-Item $zipPath -Force

Write-Host "Configuring php.ini..."
$devIni = Join-Path $targetDir 'php.ini-development'
$iniPath = Join-Path $targetDir 'php.ini'
Copy-Item $devIni $iniPath -Force

$iniText = Get-Content $iniPath -Raw
$iniText = $iniText -replace ';extension_dir = "ext"', 'extension_dir = "ext"'
$iniText = $iniText -replace ';extension=curl', 'extension=curl'
$iniText = $iniText -replace ';extension=pdo_mysql', 'extension=pdo_mysql'
$iniText = $iniText -replace ';extension=mysqli', 'extension=mysqli'
$iniText = $iniText -replace ';extension=mbstring', 'extension=mbstring'
$iniText = $iniText -replace ';extension=openssl', 'extension=openssl'
Set-Content -Path $iniPath -Value $iniText

Write-Host "PHP 8.2 setup complete!"
