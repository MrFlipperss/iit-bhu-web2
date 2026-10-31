$url = 'https://windows.php.net/downloads/releases/archives/php-8.2.35-Win32-vs16-x64.zip'
$out = 'C:\Users\Kian\Downloads\php-8.2.35.zip'
$targetDir = 'C:\Users\Kian\php82'

for ($attempt = 1; $attempt -le 20; $attempt++) {
    Write-Host "Attempt $attempt downloading PHP 8.2..."
    & curl.exe -C - -fL -o $out $url
    if ($LASTEXITCODE -eq 0) {
        Write-Host "Download complete!"
        break
    }
    Start-Sleep -Seconds 2
}

if (Test-Path $out) {
    Write-Host "Extracting to $targetDir..."
    if (!(Test-Path $targetDir)) {
        New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
    }
    Expand-Archive -Path $out -DestinationPath $targetDir -Force
    Remove-Item $out -Force

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

    Write-Host "PHP 8.2 installed and configured successfully!"
    & (Join-Path $targetDir 'php.exe') -v
}
