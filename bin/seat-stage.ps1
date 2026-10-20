#!/usr/bin/env pwsh
# Thin wrapper: seat-stage is PHP; this passes the arguments and the exit status through.
$php = if ($env:SEAT_STAGE_PHP) { $env:SEAT_STAGE_PHP } else { 'php' }
& $php (Join-Path $PSScriptRoot 'seat-stage') @args
exit $LASTEXITCODE
