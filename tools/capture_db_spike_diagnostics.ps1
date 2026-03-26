param(
    [string]$MySqlExe = 'C:\xampp\mysql\bin\mysql.exe',
    [string]$Database = 'gcc_attendance_master',
    [string]$User = 'root',
    [int]$Port = 3306,
    [string]$OutputRoot = ''
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($OutputRoot)) {
    $OutputRoot = Join-Path $PSScriptRoot '..\test-results\db-runtime-diagnostics'
}

$resolvedRoot = [System.IO.Path]::GetFullPath($OutputRoot)
$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$captureDir = Join-Path $resolvedRoot $timestamp

New-Item -ItemType Directory -Path $captureDir -Force | Out-Null

function Write-SectionFile {
    param(
        [string]$Path,
        [string]$Content
    )
    [System.IO.File]::WriteAllText($Path, $Content, [System.Text.UTF8Encoding]::new($false))
}

function Invoke-MySqlCapture {
    param(
        [string]$Sql,
        [string]$Path,
        [switch]$VerboseMode
    )

    $args = @('-u', $User)
    if ($Database) {
        $args += @('-D', $Database)
    }
    if ($VerboseMode) {
        $args += '-vvv'
    }
    $args += @('-e', $Sql)

    $content = & $MySqlExe @args 2>&1 | Out-String
    Write-SectionFile -Path $Path -Content $content
}

$meta = @(
    "Capture timestamp: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss zzz')"
    "MySQL executable: $MySqlExe"
    "Database: $Database"
    "User: $User"
    "Port: $Port"
    "Output directory: $captureDir"
)
Write-SectionFile -Path (Join-Path $captureDir '00_meta.txt') -Content ($meta -join [Environment]::NewLine)

Invoke-MySqlCapture -Sql "SHOW VARIABLES LIKE 'datadir'; SHOW VARIABLES LIKE 'port'; SHOW VARIABLES LIKE 'socket'; SHOW VARIABLES LIKE 'pid_file'; SHOW VARIABLES LIKE 'basedir';" -Path (Join-Path $captureDir '01_mysql_identity.txt') -VerboseMode
Invoke-MySqlCapture -Sql "SHOW FULL PROCESSLIST;" -Path (Join-Path $captureDir '02_processlist.txt')
Invoke-MySqlCapture -Sql "SHOW VARIABLES LIKE 'event_scheduler';" -Path (Join-Path $captureDir '03_event_scheduler.txt')
Invoke-MySqlCapture -Sql "SHOW ENGINE INNODB STATUS\G" -Path (Join-Path $captureDir '04_innodb_status.txt')

$topCpu = Get-Process |
    Sort-Object CPU -Descending |
    Select-Object -First 25 Id, ProcessName, CPU, WS, PM, Path |
    Format-Table -AutoSize |
    Out-String
Write-SectionFile -Path (Join-Path $captureDir '05_top_cpu_processes.txt') -Content $topCpu

$portLines = netstat -ano -p tcp | Select-String ":$Port"
$portContent = $portLines | Out-String
Write-SectionFile -Path (Join-Path $captureDir '06_port_netstat.txt') -Content $portContent

$pidValues = $portLines |
    ForEach-Object { ($_ -split '\s+')[-1] } |
    Where-Object { $_ -match '^\d+$' } |
    Sort-Object -Unique

$owners = foreach ($procId in $pidValues) {
    try {
        Get-Process -Id $procId | Select-Object Id, ProcessName, Path
    } catch {
        [PSCustomObject]@{
            Id = $procId
            ProcessName = '<not found>'
            Path = ''
        }
    }
}
Write-SectionFile -Path (Join-Path $captureDir '07_port_owner_processes.txt') -Content (($owners | Format-Table -AutoSize | Out-String))

$mysqlProcesses = Get-CimInstance Win32_Process |
    Where-Object { $_.Name -match '^(mysqld|mariadbd|mysql)\.exe$' } |
    Select-Object ProcessId, Name, ExecutablePath, CommandLine |
    Format-List |
    Out-String
Write-SectionFile -Path (Join-Path $captureDir '08_mysql_processes.txt') -Content $mysqlProcesses

$mysqlServices = Get-CimInstance Win32_Service |
    Where-Object { $_.PathName -match 'mysql|mariadb|xampp' -or $_.Name -match 'mysql|mariadb|xampp' -or $_.DisplayName -match 'mysql|mariadb|xampp' } |
    Select-Object Name, DisplayName, State, StartMode, ProcessId, PathName |
    Format-List |
    Out-String
Write-SectionFile -Path (Join-Path $captureDir '09_mysql_services.txt') -Content $mysqlServices

Write-Output "Diagnostics captured to: $captureDir"
