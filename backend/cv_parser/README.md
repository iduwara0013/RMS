# CPSTL layout-aware CV parser

The parser runs locally and accepts private CV bytes from Laravel. It does not expose document paths and binds only to `127.0.0.1`.

## First-time setup

From this directory:

```powershell
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
```

If Python is not in PATH, install Python 3.12 or newer first.

For scanned PDFs, install Tesseract OCR and ensure `tesseract.exe` is in PATH. Digital PDFs and DOCX files do not require Tesseract.

## Run

```powershell
.\start.ps1
```

The health endpoint is `http://127.0.0.1:8001/health`. Keep this terminal open while Laravel is running.
