#!/usr/bin/env pwsh
# Thin wrapper: seat-pace is PHP; this passes the arguments and the exit status through.
$php = if ($env:SEAT_PACE_PHP) { $env:SEAT_PACE_PHP } else { 'php' }
& $php (Join-Path $PSScriptRoot 'seat-pace') @args
exit $LASTEXITCODE
