@echo off

cmd /c "exit /b 0"

rem Add necessary dirs to the path 

set PHP_SDK_BIN_PATH=%~dp0
rem remove trailing slash
set PHP_SDK_BIN_PATH=%PHP_SDK_BIN_PATH:~0,-1%

for %%a in ("%PHP_SDK_BIN_PATH%") do set PHP_SDK_ROOT_PATH=%%~dpa
rem remove trailing slash
set PHP_SDK_ROOT_PATH=%PHP_SDK_ROOT_PATH:~0,-1%

set PHP_SDK_MSYS2_PATH=%PHP_SDK_ROOT_PATH%\msys2\usr\bin
set PHP_SDK_PHP_CMD=%PHP_SDK_BIN_PATH%\php\do_php.bat

set PATH=%PHP_SDK_BIN_PATH%;%PHP_SDK_MSYS2_PATH%;%PATH%

for /f "tokens=1* delims=: " %%a in ('link /?') do (
	set PHP_SDK_VC_TOOLSET_VER=%%b
	goto break0
)
:break0
set PHP_SDK_VC_TOOLSET_VER=%PHP_SDK_VC_TOOLSET_VER:~-13%

if /i "%VSCMD_ARG_TGT_ARCH%"=="arm64" call :stage_pgo_tool

exit /b %errorlevel%

:stage_pgo_tool
if not defined VCToolsInstallDir goto :eof

set "PHP_SDK_PGO_PATH=%PHP_SDK_BIN_PATH%\pgo"
if not exist "%PHP_SDK_PGO_PATH%" md "%PHP_SDK_PGO_PATH%" >nul 2>&1

call :copy_pgo_tool "%VCToolsInstallDir%bin\Hostarm64\arm64"
if exist "%PHP_SDK_PGO_PATH%\pgomgr.exe" goto :prepend_pgo_path

call :copy_pgo_tool "%VCToolsInstallDir%bin\Hostx64\arm64"
if exist "%PHP_SDK_PGO_PATH%\pgomgr.exe" goto :prepend_pgo_path

call :copy_pgo_tool "%VCToolsInstallDir%bin\Hostx64\x64"
if exist "%PHP_SDK_PGO_PATH%\pgomgr.exe" goto :prepend_pgo_path

goto :eof

:copy_pgo_tool
if "%~1"=="" goto :eof
if not exist "%~1\pgomgr.exe" goto :eof

copy /y "%~1\pgomgr.exe" "%PHP_SDK_PGO_PATH%\pgomgr.exe" >nul 2>&1

goto :eof

:prepend_pgo_path
set "PATH=%PHP_SDK_PGO_PATH%;%PATH%"

goto :eof

