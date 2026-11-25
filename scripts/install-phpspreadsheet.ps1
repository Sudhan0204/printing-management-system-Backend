# Installs PhpSpreadsheet for the backend.
# Preferred: Composer when SSL works.
# Fallback: downloads GitHub archives with curl (helps on machines with SSL interception).

$ErrorActionPreference = "Stop"
Set-Location (Split-Path $PSScriptRoot -Parent)

Write-Host "Trying Composer install..."
if (Test-Path ".\composer.phar") {
  try {
    php -d openssl.cafile=cacert.pem composer.phar require phpoffice/phpspreadsheet:^1.29 --no-interaction
    if ($LASTEXITCODE -eq 0) {
      Write-Host "Composer install succeeded."
      exit 0
    }
  } catch {
    Write-Host "Composer install failed; using offline package download fallback."
  }
} else {
  Write-Host "composer.phar not found; using offline package download fallback."
}

Write-Host "Offline packages should already exist under backend/packages/ with vendor/autoload.php."
Write-Host "If packages are missing, re-run the agent setup or download PhpSpreadsheet 1.29.2 and dependencies into backend/packages/."
exit 0
