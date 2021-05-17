wget -qO- https://packages.microsoft.com/keys/microsoft.asc | sudo apt-key add -
sudo add-apt-repository "$(wget -qO- https://packages.microsoft.com/config/ubuntu/16.04/mssql-server-$1.list)"
sudo add-apt-repository "$(wget -qO- https://packages.microsoft.com/config/ubuntu/16.04/prod.list)"
sudo apt-get update
sudo ACCEPT_EULA=Y apt-get install -y mssql-server mssql-tools unixodbc-dev
export MSSQL_SA_PASSWORD=Password12!
export ACCEPT_EULA=Y
export MSSQL_PID=Evaluation
export SIMPLETEST_BASE_URL=http://127.0.0.1
sudo /opt/mssql/bin/mssql-conf setup
sleep 15
# testing collate as LATIN1_GENERAL_100_CI_AS_SC_UTF8 or Latin1_General_CI_AI
/opt/mssql-tools/bin/sqlcmd -P Password12! -S localhost -U SA -Q "CREATE DATABASE mydrupalsite COLLATE ${DB_COLLATION}"
# Install the pdo_sqlsrv extension
sudo ACCEPT_EULA=Y apt-get -y install msodbcsql17 unixodbc-dev gcc g++ make autoconf libc-dev pkg-config
pecl install sqlsrv pdo_sqlsrv
yes "autodetect" | pecl install yaml
# Install REGEX CLR
wget https://github.com/Beakerboy/drupal-sqlsrv-regex/releases/download/1.0/RegEx.dll
sudo mv RegEx.dll /var/opt/mssql/data/
/opt/mssql-tools/bin/sqlcmd -P Password12! -S localhost -U SA -d mydrupalsite -Q "EXEC sp_configure 'show advanced options', 1; RECONFIGURE; EXEC sp_configure 'clr strict security', 0; RECONFIGURE; EXEC sp_configure 'clr enable', 1; RECONFIGURE"
/opt/mssql-tools/bin/sqlcmd -P Password12! -S localhost -U SA -d mydrupalsite -Q "CREATE ASSEMBLY Regex from '/var/opt/mssql/data/RegEx.dll' WITH PERMISSION_SET = SAFE"
/opt/mssql-tools/bin/sqlcmd -P Password12! -S localhost -U SA -d mydrupalsite -Q "CREATE FUNCTION dbo.REGEXP(@pattern NVARCHAR(100), @matchString NVARCHAR(100)) RETURNS bit EXTERNAL NAME Regex.RegExCompiled.RegExCompiledMatch"
