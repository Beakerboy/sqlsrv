
Invoke-Sqlcmd -Database mydrupalsite -Username sa -Password "Password12!" -Query "EXEC sp_configure 'show advanced options', 1; RECONFIGURE; EXEC sp_configure 'clr enable', 1; RECONFIGURE"
Invoke-Sqlcmd -Database mydrupalsite -Username sa -Password "Password12!" -Query "CREATE ASSEMBLY Regex from 'C:\testlogs\RegEx.dll' WITH PERMISSION_SET = SAFE"
Invoke-Sqlcmd -Database mydrupalsite -Username sa -Password "Password12!" -Query "CREATE FUNCTION dbo.REGEXP(@pattern NVARCHAR(100), @matchString NVARCHAR(100)) RETURNS bit EXTERNAL NAME Regex.RegExCompiled.RegExCompiledMatch"
