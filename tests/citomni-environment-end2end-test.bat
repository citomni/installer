@echo off
setlocal EnableExtensions DisableDelayedExpansion
chcp 65001 >nul

rem CitOmni environment happy-path end-to-end smoke test.
rem
rem Run from an already materialized app root with citomni/http installed.
rem The test normalizes the app to a clean dev environment before testing:
rem
rem   prepare dev
rem   dev -> stage (dry-run + apply)
rem   stage -> prod (dry-run + apply)
rem   prod -> dev (apply)
rem
rem It verifies:
rem - recorded environment
rem - Composer classmap-authoritative posture
rem - installer status
rem - rendered environment-aware HTTP targets
rem - backups contain the bytes from the previous environment
rem
rem The script is intentionally linear. It does not use CALL :label subroutines,
rem avoiding CMD batch-label parsing issues in long smoke-test files.

if not exist "_test" mkdir "_test"

for /f %%I in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmmss"') do set "TEST_TIMESTAMP=%%I"
set "LOG=_test\end2end-test-%TEST_TIMESTAMP%.txt"
set "INSTALLER=vendor\bin\citomni-installer.bat"

> "%LOG%" echo CitOmni environment end-to-end smoke test
>> "%LOG%" echo Started: %DATE% %TIME%
>> "%LOG%" echo App root: %CD%
>> "%LOG%" echo.

if not exist "%INSTALLER%" (
	>> "%LOG%" echo FAIL: Composer installer batch proxy was not found: %INSTALLER%
	echo FAIL: Composer installer batch proxy was not found: %INSTALLER%
	echo Log: %CD%\%LOG%
	exit /b 1
)

if not exist "vendor\citomni\http\install\manifest.php" (
	>> "%LOG%" echo FAIL: citomni/http manifest was not found. This smoke test requires citomni/http.
	echo FAIL: citomni/http manifest was not found. This smoke test requires citomni/http.
	echo Log: %CD%\%LOG%
	exit /b 1
)

if not exist "var\state\citomni\installer-scaffold.php" (
	>> "%LOG%" echo FAIL: Installer state file was not found. Start from a materialized app.
	echo FAIL: Installer state file was not found. Start from a materialized app.
	echo Log: %CD%\%LOG%
	exit /b 1
)

echo [TEST] PREPARE DEV
>> "%LOG%" echo.
>> "%LOG%" echo ==== PREPARE DEV ====
call "%INSTALLER%" environment dev >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Could not normalize the app to dev.
	echo FAIL: Could not normalize the app to dev.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "$s=require 'var/state/citomni/installer-scaffold.php'; $actual=$s['environment']??null; echo 'state.environment=', $actual??'<missing>', PHP_EOL; exit($actual==='dev'?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: PREPARE DEV did not record environment=dev.
	echo FAIL: PREPARE DEV did not record environment=dev.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "$c=json_decode(file_get_contents('composer.json'),true); $actual=$c['config']['classmap-authoritative']??null; echo 'classmap-authoritative='; var_export($actual); echo PHP_EOL; exit($actual===false?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: PREPARE DEV did not set classmap-authoritative=false.
	echo FAIL: PREPARE DEV did not set classmap-authoritative=false.
	echo Log: %CD%\%LOG%
	exit /b 1
)

call "%INSTALLER%" status >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Installer status is not clean after PREPARE DEV.
	echo FAIL: Installer status is not clean after PREPARE DEV.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "require 'vendor/autoload.php'; $env='dev'; $v=(new CitOmni\Installer\Support\PlaceholderResolver(getcwd()))->resolve(); $r=new CitOmni\Installer\Support\ScaffoldRenderer(); $m=require 'vendor/citomni/http/install/manifest.php'; $ok=true; foreach($m['files'] as $f){if(!isset($f['environments']))continue; $source=$f['environments'][$env]['source']; $stub=file_get_contents('vendor/citomni/http/'.$source); $expected=$r->render($stub,$v); $actual=is_file($f['target'])?file_get_contents($f['target']):null; $match=$actual===$expected; printf('%%-25s %%s'.PHP_EOL,$f['target'],$match?'MATCH':'DIFF'); if(!$match)$ok=false;} exit($ok?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: PREPARE DEV HTTP targets do not match rendered dev scaffold.
	echo FAIL: PREPARE DEV HTTP targets do not match rendered dev scaffold.
	echo Log: %CD%\%LOG%
	exit /b 1
)

echo [TEST] STAGE DRY RUN
>> "%LOG%" echo.
>> "%LOG%" echo ==== STAGE DRY RUN ====
call "%INSTALLER%" environment stage --dry-run >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: environment stage --dry-run failed.
	echo FAIL: environment stage --dry-run failed.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "$s=require 'var/state/citomni/installer-scaffold.php'; $actual=$s['environment']??null; echo 'state.environment=', $actual??'<missing>', PHP_EOL; exit($actual==='dev'?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Stage dry-run changed installer state.
	echo FAIL: Stage dry-run changed installer state.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "$c=json_decode(file_get_contents('composer.json'),true); $actual=$c['config']['classmap-authoritative']??null; echo 'classmap-authoritative='; var_export($actual); echo PHP_EOL; exit($actual===false?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Stage dry-run changed Composer posture.
	echo FAIL: Stage dry-run changed Composer posture.
	echo Log: %CD%\%LOG%
	exit /b 1
)

call "%INSTALLER%" status >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Installer status is not clean after stage dry-run.
	echo FAIL: Installer status is not clean after stage dry-run.
	echo Log: %CD%\%LOG%
	exit /b 1
)

echo [TEST] STAGE APPLY
>> "%LOG%" echo.
>> "%LOG%" echo ==== STAGE APPLY ====
call "%INSTALLER%" environment stage --format=json > "_test\environment-stage-result.json" 2>&1
set "ENVIRONMENT_EXIT=%ERRORLEVEL%"
type "_test\environment-stage-result.json" >> "%LOG%"
if not "%ENVIRONMENT_EXIT%"=="0" (
	>> "%LOG%" echo FAIL: environment stage failed.
	echo FAIL: environment stage failed.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "$s=require 'var/state/citomni/installer-scaffold.php'; $actual=$s['environment']??null; echo 'state.environment=', $actual??'<missing>', PHP_EOL; exit($actual==='stage'?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Stage apply did not record environment=stage.
	echo FAIL: Stage apply did not record environment=stage.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "$c=json_decode(file_get_contents('composer.json'),true); $actual=$c['config']['classmap-authoritative']??null; echo 'classmap-authoritative='; var_export($actual); echo PHP_EOL; exit($actual===true?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Stage apply did not set classmap-authoritative=true.
	echo FAIL: Stage apply did not set classmap-authoritative=true.
	echo Log: %CD%\%LOG%
	exit /b 1
)

call "%INSTALLER%" status >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Installer status is not clean after stage apply.
	echo FAIL: Installer status is not clean after stage apply.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "require 'vendor/autoload.php'; $env='stage'; $v=(new CitOmni\Installer\Support\PlaceholderResolver(getcwd()))->resolve(); $r=new CitOmni\Installer\Support\ScaffoldRenderer(); $m=require 'vendor/citomni/http/install/manifest.php'; $ok=true; foreach($m['files'] as $f){if(!isset($f['environments']))continue; $source=$f['environments'][$env]['source']; $stub=file_get_contents('vendor/citomni/http/'.$source); $expected=$r->render($stub,$v); $actual=is_file($f['target'])?file_get_contents($f['target']):null; $match=$actual===$expected; printf('%%-25s %%s'.PHP_EOL,$f['target'],$match?'MATCH':'DIFF'); if(!$match)$ok=false;} exit($ok?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Stage HTTP targets do not match rendered stage scaffold.
	echo FAIL: Stage HTTP targets do not match rendered stage scaffold.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "require 'vendor/autoload.php'; $env='dev'; $result=json_decode(file_get_contents('_test/environment-stage-result.json'),true); $backup=$result['backup_dir']??null; echo 'backup=', $backup??'<missing>', PHP_EOL; if($backup===null)exit(1); $v=(new CitOmni\Installer\Support\PlaceholderResolver(getcwd()))->resolve(); $r=new CitOmni\Installer\Support\ScaffoldRenderer(); $m=require 'vendor/citomni/http/install/manifest.php'; $ok=true; foreach($m['files'] as $f){if(!isset($f['environments']))continue; $source=$f['environments'][$env]['source']; $stub=file_get_contents('vendor/citomni/http/'.$source); $expected=$r->render($stub,$v); $path=$backup.'/'.$f['target']; $actual=is_file($path)?file_get_contents($path):null; $match=$actual===$expected; printf('%%-25s %%s'.PHP_EOL,$f['target'],$match?'MATCH':'DIFF'); if(!$match)$ok=false;} exit($ok?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Reported backup does not match rendered dev scaffold after stage apply.
	echo FAIL: Reported backup does not match rendered dev scaffold after stage apply.
	echo Log: %CD%\%LOG%
	exit /b 1
)

echo [TEST] PROD DRY RUN
>> "%LOG%" echo.
>> "%LOG%" echo ==== PROD DRY RUN ====
call "%INSTALLER%" environment prod --dry-run >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: environment prod --dry-run failed.
	echo FAIL: environment prod --dry-run failed.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "$s=require 'var/state/citomni/installer-scaffold.php'; $actual=$s['environment']??null; echo 'state.environment=', $actual??'<missing>', PHP_EOL; exit($actual==='stage'?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Prod dry-run changed installer state.
	echo FAIL: Prod dry-run changed installer state.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "$c=json_decode(file_get_contents('composer.json'),true); $actual=$c['config']['classmap-authoritative']??null; echo 'classmap-authoritative='; var_export($actual); echo PHP_EOL; exit($actual===true?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Prod dry-run changed Composer posture.
	echo FAIL: Prod dry-run changed Composer posture.
	echo Log: %CD%\%LOG%
	exit /b 1
)

call "%INSTALLER%" status >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Installer status is not clean after prod dry-run.
	echo FAIL: Installer status is not clean after prod dry-run.
	echo Log: %CD%\%LOG%
	exit /b 1
)

echo [TEST] PROD APPLY
>> "%LOG%" echo.
>> "%LOG%" echo ==== PROD APPLY ====
call "%INSTALLER%" environment prod --format=json > "_test\environment-prod-result.json" 2>&1
set "ENVIRONMENT_EXIT=%ERRORLEVEL%"
type "_test\environment-prod-result.json" >> "%LOG%"
if not "%ENVIRONMENT_EXIT%"=="0" (
	>> "%LOG%" echo FAIL: environment prod failed.
	echo FAIL: environment prod failed.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "$s=require 'var/state/citomni/installer-scaffold.php'; $actual=$s['environment']??null; echo 'state.environment=', $actual??'<missing>', PHP_EOL; exit($actual==='prod'?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Prod apply did not record environment=prod.
	echo FAIL: Prod apply did not record environment=prod.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "$c=json_decode(file_get_contents('composer.json'),true); $actual=$c['config']['classmap-authoritative']??null; echo 'classmap-authoritative='; var_export($actual); echo PHP_EOL; exit($actual===true?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Prod apply did not set classmap-authoritative=true.
	echo FAIL: Prod apply did not set classmap-authoritative=true.
	echo Log: %CD%\%LOG%
	exit /b 1
)

call "%INSTALLER%" status >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Installer status is not clean after prod apply.
	echo FAIL: Installer status is not clean after prod apply.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "require 'vendor/autoload.php'; $env='prod'; $v=(new CitOmni\Installer\Support\PlaceholderResolver(getcwd()))->resolve(); $r=new CitOmni\Installer\Support\ScaffoldRenderer(); $m=require 'vendor/citomni/http/install/manifest.php'; $ok=true; foreach($m['files'] as $f){if(!isset($f['environments']))continue; $source=$f['environments'][$env]['source']; $stub=file_get_contents('vendor/citomni/http/'.$source); $expected=$r->render($stub,$v); $actual=is_file($f['target'])?file_get_contents($f['target']):null; $match=$actual===$expected; printf('%%-25s %%s'.PHP_EOL,$f['target'],$match?'MATCH':'DIFF'); if(!$match)$ok=false;} exit($ok?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Prod HTTP targets do not match rendered prod scaffold.
	echo FAIL: Prod HTTP targets do not match rendered prod scaffold.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "require 'vendor/autoload.php'; $env='stage'; $result=json_decode(file_get_contents('_test/environment-prod-result.json'),true); $backup=$result['backup_dir']??null; echo 'backup=', $backup??'<missing>', PHP_EOL; if($backup===null)exit(1); $v=(new CitOmni\Installer\Support\PlaceholderResolver(getcwd()))->resolve(); $r=new CitOmni\Installer\Support\ScaffoldRenderer(); $m=require 'vendor/citomni/http/install/manifest.php'; $ok=true; foreach($m['files'] as $f){if(!isset($f['environments']))continue; $source=$f['environments'][$env]['source']; $stub=file_get_contents('vendor/citomni/http/'.$source); $expected=$r->render($stub,$v); $path=$backup.'/'.$f['target']; $actual=is_file($path)?file_get_contents($path):null; $match=$actual===$expected; printf('%%-25s %%s'.PHP_EOL,$f['target'],$match?'MATCH':'DIFF'); if(!$match)$ok=false;} exit($ok?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Reported backup does not match rendered stage scaffold after prod apply.
	echo FAIL: Reported backup does not match rendered stage scaffold after prod apply.
	echo Log: %CD%\%LOG%
	exit /b 1
)

echo [TEST] DEV APPLY
>> "%LOG%" echo.
>> "%LOG%" echo ==== DEV APPLY ====
call "%INSTALLER%" environment dev --format=json > "_test\environment-dev-result.json" 2>&1
set "ENVIRONMENT_EXIT=%ERRORLEVEL%"
type "_test\environment-dev-result.json" >> "%LOG%"
if not "%ENVIRONMENT_EXIT%"=="0" (
	>> "%LOG%" echo FAIL: environment dev failed.
	echo FAIL: environment dev failed.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "$s=require 'var/state/citomni/installer-scaffold.php'; $actual=$s['environment']??null; echo 'state.environment=', $actual??'<missing>', PHP_EOL; exit($actual==='dev'?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Dev apply did not record environment=dev.
	echo FAIL: Dev apply did not record environment=dev.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "$c=json_decode(file_get_contents('composer.json'),true); $actual=$c['config']['classmap-authoritative']??null; echo 'classmap-authoritative='; var_export($actual); echo PHP_EOL; exit($actual===false?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Dev apply did not set classmap-authoritative=false.
	echo FAIL: Dev apply did not set classmap-authoritative=false.
	echo Log: %CD%\%LOG%
	exit /b 1
)

call "%INSTALLER%" status >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Installer status is not clean after dev apply.
	echo FAIL: Installer status is not clean after dev apply.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "require 'vendor/autoload.php'; $env='dev'; $v=(new CitOmni\Installer\Support\PlaceholderResolver(getcwd()))->resolve(); $r=new CitOmni\Installer\Support\ScaffoldRenderer(); $m=require 'vendor/citomni/http/install/manifest.php'; $ok=true; foreach($m['files'] as $f){if(!isset($f['environments']))continue; $source=$f['environments'][$env]['source']; $stub=file_get_contents('vendor/citomni/http/'.$source); $expected=$r->render($stub,$v); $actual=is_file($f['target'])?file_get_contents($f['target']):null; $match=$actual===$expected; printf('%%-25s %%s'.PHP_EOL,$f['target'],$match?'MATCH':'DIFF'); if(!$match)$ok=false;} exit($ok?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Dev HTTP targets do not match rendered dev scaffold.
	echo FAIL: Dev HTTP targets do not match rendered dev scaffold.
	echo Log: %CD%\%LOG%
	exit /b 1
)

php -r "require 'vendor/autoload.php'; $env='prod'; $result=json_decode(file_get_contents('_test/environment-dev-result.json'),true); $backup=$result['backup_dir']??null; echo 'backup=', $backup??'<missing>', PHP_EOL; if($backup===null)exit(1); $v=(new CitOmni\Installer\Support\PlaceholderResolver(getcwd()))->resolve(); $r=new CitOmni\Installer\Support\ScaffoldRenderer(); $m=require 'vendor/citomni/http/install/manifest.php'; $ok=true; foreach($m['files'] as $f){if(!isset($f['environments']))continue; $source=$f['environments'][$env]['source']; $stub=file_get_contents('vendor/citomni/http/'.$source); $expected=$r->render($stub,$v); $path=$backup.'/'.$f['target']; $actual=is_file($path)?file_get_contents($path):null; $match=$actual===$expected; printf('%%-25s %%s'.PHP_EOL,$f['target'],$match?'MATCH':'DIFF'); if(!$match)$ok=false;} exit($ok?0:1);" >> "%LOG%" 2>&1
if errorlevel 1 (
	>> "%LOG%" echo FAIL: Reported backup does not match rendered prod scaffold after dev apply.
	echo FAIL: Reported backup does not match rendered prod scaffold after dev apply.
	echo Log: %CD%\%LOG%
	exit /b 1
)

>> "%LOG%" echo.
>> "%LOG%" echo ==== FINAL RESULT ====
>> "%LOG%" echo PASS: dev -^> stage -^> prod -^> dev completed successfully.
>> "%LOG%" echo Finished: %DATE% %TIME%

echo.
echo PASS
echo Log: %CD%\%LOG%
exit /b 0
