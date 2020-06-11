Start-Service 'MSSQL$SQL2016' | out-null
Set-Service 'SQLAgent$SQL2016' -StartupType Manual | out-null
# configure RPC server
netsh advfirewall firewall set rule group="Windows Management Instrumentation (WMI)" new enable=yes
# is this needed
Start-Service W3SVC | out-null
Invoke-Sqlcmd -Username sa -Password "Password12!" -Query "CREATE DATABASE mydrupalsite"
# Enable Regex
(New-Object Net.WebClient).DownloadFile('https://github.com/Beakerboy/drupal-sqlsrv-regex/releases/download/1.0/RegEx.dll', 'C:\testlogs\RegEx.dll')
Invoke-Sqlcmd -Database mydrupalsite -Username sa -Password "Password12!" -Query "EXEC sp_configure 'show advanced options', 1; RECONFIGURE; EXEC sp_configure 'clr enable', 1; RECONFIGURE"
Invoke-Sqlcmd -Database mydrupalsite -Username sa -Password "Password12!" -Query "CREATE ASSEMBLY Regex from 'C:\testlogs\RegEx.dll' WITH PERMISSION_SET = SAFE"
Invoke-Sqlcmd -Database mydrupalsite -Username sa -Password "Password12!" -Query "CREATE FUNCTION dbo.REGEXP(@pattern NVARCHAR(100), @matchString NVARCHAR(100)) RETURNS bit EXTERNAL NAME Regex.RegExCompiled.RegExCompiledMatch"
