# open-server.ps1
# Detect php.ini
Write-Host "Detecting php.ini location..."
$iniInfo = & php --ini
$loadedIni = ($iniInfo | ForEach-Object {
    if ($_ -match "Loaded Configuration File") { ($_ -split ":\s*",2)[1].Trim() }
})

if (Test-Path $loadedIni) {
    Write-Host "php.ini found at: $loadedIni"
} else {
    Write-Host "php.ini not found."
}

# Set paths
$phpPath = "C:\Users\RJ COMPUTER\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe"
$publicPath = "public"

# Start Slim server
Write-Host "Starting Slim PHP server on http://localhost:8080"
& $phpPath -S localhost:8080 -t $publicPath
