$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$phpPath = Join-Path $projectRoot 'php\php.exe'
$digestScript = Join-Path $PSScriptRoot 'send_daily_low_stock_digest.php'
$taskName = 'Capstone Daily Low Stock Email'

if (-not (Test-Path -LiteralPath $phpPath -PathType Leaf)) {
    throw "PHP CLI was not found at '$phpPath'."
}
if (-not (Test-Path -LiteralPath $digestScript -PathType Leaf)) {
    throw "Daily digest script was not found at '$digestScript'."
}

$action = New-ScheduledTaskAction -Execute $phpPath -Argument ('"{0}"' -f $digestScript) -WorkingDirectory $PSScriptRoot
$trigger = New-ScheduledTaskTrigger -Daily -At '9:00 AM'
$principal = New-ScheduledTaskPrincipal -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Sends the daily email summary of products at or below their stock thresholds.' -Force | Out-Null
Write-Output "Scheduled '$taskName' to run daily at 9:00 AM for $([System.Security.Principal.WindowsIdentity]::GetCurrent().Name)."
