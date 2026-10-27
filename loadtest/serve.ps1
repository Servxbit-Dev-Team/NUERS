# Local load-test server: nginx in front of N php-cgi workers (Windows has no php-fpm).
#
#   .\loadtest\serve.ps1 -Action start -AppDir <app copy> -RunDir <scratch dir> -Workers 12
#   .\loadtest\serve.ps1 -Action stop  -RunDir <scratch dir>
#
# php-cgi serves one connection at a time, so upstream keepalive is left off: an idle kept-open connection
# would pin a worker while other requests wait for it (seen as 504s at higher concurrency).
#
# Database settings come from DB_* variables already set in the calling shell. Point them at a throwaway
# MySQL only. This script never reads or writes a .env file.
param(
    [Parameter(Mandatory)][ValidateSet('start', 'stop')][string]$Action,
    [Parameter(Mandatory)][string]$RunDir,
    [string]$AppDir,
    [int]$Workers = 12,
    [int]$Port = 8090,
    [string]$PhpCgi = 'C:\php\php-cgi.exe',
    [string]$Nginx = 'C:\laragon\bin\nginx\nginx-1.28.2\nginx.exe'
)

$ErrorActionPreference = 'Stop'
New-Item -ItemType Directory -Force $RunDir | Out-Null
$pidFile = Join-Path $RunDir 'pids.txt'

if ($Action -eq 'stop') {
    if (Test-Path $pidFile) {
        Get-Content $pidFile | ForEach-Object { Stop-Process -Id $_ -Force -ErrorAction SilentlyContinue }
        Remove-Item $pidFile
    }
    Write-Output 'stopped'
    return
}

if (-not $AppDir) { throw '-AppDir is required for start' }
if ($env:DB_HOST -notin @('127.0.0.1', 'localhost')) { throw "Refusing to start: DB_HOST must be local (got '$($env:DB_HOST)')." }
if ($env:DB_DATABASE -notlike '*load*') { throw "Refusing to start: DB_DATABASE must contain 'load' (got '$($env:DB_DATABASE)')." }

$env:APP_DEBUG = 'false'
if (-not $env:LOG_CHANNEL) { $env:LOG_CHANNEL = 'null' }
$env:PHP_FCGI_MAX_REQUESTS = '0'
$ids = @()
$ports = 9101..(9100 + $Workers)
foreach ($p in $ports) {
    $proc = Start-Process -FilePath $PhpCgi -WindowStyle Hidden -PassThru -WorkingDirectory $AppDir `
        -ArgumentList @('-b', "127.0.0.1:$p", '-d', 'opcache.enable=1', '-d', 'opcache.validate_timestamps=0', '-d', 'opcache.memory_consumption=256', '-d', 'memory_limit=256M', '-d', 'extension_dir=ext', '-d', 'extension=pdo_mysql', '-d', 'extension=mbstring', '-d', 'extension=openssl', '-d', 'extension=fileinfo')
    $ids += $proc.Id
}

$root = ($AppDir -replace '\\', '/') + '/public'
$run = $RunDir -replace '\\', '/'
$upstream = ($ports | ForEach-Object { "        server 127.0.0.1:$_;" }) -join "`n"
@"
worker_processes 4;
pid $run/nginx.pid;
error_log $run/nginx-error.log;
events { worker_connections 8192; }
http {
    access_log off;
    default_type application/octet-stream;
    upstream php {
$upstream
    }
    server {
        listen 127.0.0.1:$Port;
        root $root;
        client_max_body_size 2m;
        location / { try_files `$uri /index.php?`$query_string; }
        location ~ \.php$ {
            fastcgi_pass php;
            fastcgi_read_timeout 120s;
            fastcgi_param SCRIPT_FILENAME `$document_root`$fastcgi_script_name;
            fastcgi_param SCRIPT_NAME `$fastcgi_script_name;
            fastcgi_param QUERY_STRING `$query_string;
            fastcgi_param REQUEST_METHOD `$request_method;
            fastcgi_param CONTENT_TYPE `$content_type;
            fastcgi_param CONTENT_LENGTH `$content_length;
            fastcgi_param REQUEST_URI `$request_uri;
            fastcgi_param DOCUMENT_URI `$document_uri;
            fastcgi_param DOCUMENT_ROOT `$document_root;
            fastcgi_param SERVER_PROTOCOL `$server_protocol;
            fastcgi_param REMOTE_ADDR `$remote_addr;
            fastcgi_param SERVER_NAME `$host;
            fastcgi_param SERVER_PORT `$server_port;
            fastcgi_param REDIRECT_STATUS 200;
        }
    }
}
"@ | Set-Content -Encoding ascii (Join-Path $RunDir 'nginx.conf')

$n = Start-Process -FilePath $Nginx -WindowStyle Hidden -PassThru -ArgumentList @('-p', "$run/", '-c', "$run/nginx.conf")
$ids += $n.Id
$ids | Set-Content $pidFile
Start-Sleep 2
Write-Output "started $Workers php-cgi workers behind nginx on http://127.0.0.1:$Port (pids in $pidFile)"
