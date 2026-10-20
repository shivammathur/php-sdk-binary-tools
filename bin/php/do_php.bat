@echo off
"%~dp0php.exe" -n "%~dp0dispatch.php" %*
exit /b %errorlevel%
