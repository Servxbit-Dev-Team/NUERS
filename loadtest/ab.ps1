# One measurement round for A/B comparisons: starts the server, runs the same scenarios, prints a compact table, stops the server.
#
#   .\loadtest\ab.ps1 -AppDir <app copy> -RunDir <scratch> -Keys <keys.json> -Label A
#
# Expects the DB_* / APP_KEY / NUERS_LOADTEST environment of the throwaway MySQL (see README). Output is one line per scenario.
param(
    [Parameter(Mandatory)][string]$AppDir,
    [Parameter(Mandatory)][string]$RunDir,
    [Parameter(Mandatory)][string]$Keys,
    [string]$Label = 'run',
    [int]$Duration = 20,
    [int]$Workers = 12
)

# node prints progress to stderr; with 'Stop' PowerShell would treat that as a failure.
$ErrorActionPreference = 'Continue'
$serve = Join-Path $AppDir 'loadtest\serve.ps1'
& $serve -Action start -AppDir $AppDir -RunDir $RunDir -Workers $Workers | Out-Null
Start-Sleep 2
Set-Location $AppDir
try {
    # Warm opcache and connections so the first measured scenario is not penalised.
    node loadtest/run.mjs --keys $Keys --scenario many --concurrency 12 --duration 5 --warmup 0 2>$null | Out-Null

    $watch = Start-Job -ScriptBlock {
        param($dir, $envs)
        Set-Location $dir
        foreach ($k in $envs.Keys) { Set-Item "Env:$k" $envs[$k] }
        Start-Sleep 7
        php loadtest/lockwatch.php 15 100
    } -ArgumentList $AppDir, @{ APP_KEY = $env:APP_KEY; DB_HOST = $env:DB_HOST; DB_PORT = $env:DB_PORT; DB_DATABASE = $env:DB_DATABASE; DB_USERNAME = $env:DB_USERNAME; DB_PASSWORD = $env:DB_PASSWORD; NUERS_LOADTEST = '1' }

    foreach ($sc in @(@('many', 32), @('many', 32), @('single', 16))) {
        $r = node loadtest/run.mjs --keys $Keys --scenario $sc[0] --concurrency $sc[1] --duration $Duration --warmup 5 2>$null | ConvertFrom-Json
        '{0} | {1,-6} c={2,-3} {3,6} accepted/s  p50 {4,7} ms  p99 {5,7} ms  in-flight {6,4}  status {7}' -f $Label, $sc[0], $sc[1], $r.accepted_per_s, $r.latency_ms.p50, $r.latency_ms.p99, $r.avg_in_flight, ($r.status | ConvertTo-Json -Compress)
    }
    $lw = (Receive-Job $watch -Wait | Out-String) | ConvertFrom-Json
    '{0} | lock waits during first c=32 run: {1} waits, avg {2} ms, max {3} ms; samples with any wait {4}/{5}; where: {6}' -f $Label, $lw.row_lock_waits_delta, $lw.row_lock_time_avg_ms, $lw.row_lock_time_max_ms, $lw.samples_with_any_lock_wait, $lw.samples, (($lw.waits_by_table_index_mode | ConvertTo-Json -Compress))
}
finally {
    & $serve -Action stop -RunDir $RunDir | Out-Null
    Get-CimInstance Win32_Process | Where-Object { ($_.Name -eq 'nginx.exe' -and $_.CommandLine -like "*$($RunDir -replace '\\','/')*") -or ($_.Name -eq 'php-cgi.exe' -and $_.CommandLine -like '*127.0.0.1:91*') } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}
