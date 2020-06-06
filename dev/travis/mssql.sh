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
phpenv config-add dev/travis/travis-7.x.ini
