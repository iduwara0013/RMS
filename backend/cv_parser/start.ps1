$ErrorActionPreference = 'Stop'
$python = Join-Path $PSScriptRoot '.venv\Scripts\python.exe'

if (-not (Test-Path $python)) {
    throw 'Parser environment is missing. Create it and install requirements first.'
}

Push-Location $PSScriptRoot
try {
    & $python -m uvicorn main:app --host 127.0.0.1 --port 8001
}
finally {
    Pop-Location
}
