@echo off
setlocal EnableExtensions
cd /d "%~dp0"

REM UTF-8 console
chcp 65001 >nul

where git.exe >nul 2>nul
if errorlevel 1 (
    echo [ERR] git.exe not found in PATH
    pause
    exit /b 1
)

echo.
echo === git status ===
git.exe status -sb
echo.

REM Stage everything (respects .gitignore)
git.exe add -A
if errorlevel 1 (
    echo [ERR] git add failed
    pause
    exit /b 1
)

REM Reliable "has changes?" check via porcelain (avoids broken errorlevel after git diff)
set "HAS_CHANGES=0"
for /f "delims=" %%L in ('git.exe status --porcelain') do (
    set "HAS_CHANGES=1"
)

set "COMMENT="
set /p "COMMENT=Commit message (Enter = update): "
if "%COMMENT%"=="" set "COMMENT=update"

if "%HAS_CHANGES%"=="0" (
    echo [INFO] No new changes to commit.
) else (
    echo.
    echo === staged ===
    git.exe status --short
    echo.
    git.exe commit -m "%COMMENT%"
    if errorlevel 1 (
        echo [ERR] commit failed
        pause
        exit /b 1
    )
    echo [OK] committed: %COMMENT%
)

REM Push if remote exists / create if not
git.exe remote get-url origin >nul 2>nul
if errorlevel 1 (
    echo [INFO] No origin. Creating GitHub repo...
    where gh.exe >nul 2>nul
    if errorlevel 1 (
        echo [ERR] Install GitHub CLI and run: gh auth login
        pause
        exit /b 1
    )
    for %%I in ("%~dp0.") do set "REPO_NAME=%%~nxI"
    gh.exe repo create "%REPO_NAME%" --private --source=. --remote=origin --push
    if errorlevel 1 (
        echo [ERR] gh repo create failed
        pause
        exit /b 1
    )
    goto :done
)

for /f "delims=" %%B in ('git.exe rev-parse --abbrev-ref HEAD') do set "BRANCH=%%B"
echo.
echo === push origin/%BRANCH% ===
git.exe push -u origin "%BRANCH%"
if errorlevel 1 (
    echo [ERR] push failed
    pause
    exit /b 1
)

:done
echo.
echo [OK] done
git.exe status -sb
echo.
pause
endlocal
