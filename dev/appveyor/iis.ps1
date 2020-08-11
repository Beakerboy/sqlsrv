import-module WebAdministration

###############################################################
# Adds a FastCGI process pool in IIS
###############################################################
$php = 'C:\tools\php\php-cgi.exe'
$configPath = get-webconfiguration 'system.webServer/fastcgi/application' | where-object { $_.fullPath -eq $php }
if (!$configPath) {
    add-webconfiguration 'system.webserver/fastcgi' -value @{'fullPath' = $php }
}

###############################################################
# Create IIS handler mapping for handling PHP requests
###############################################################
$handlerName = "PHP 7"
$handler = get-webconfiguration 'system.webserver/handlers/add' | where-object { $_.Name -eq $handlerName }
if (!$handler) {
    add-webconfiguration 'system.webServer/handlers' -Value @{
        Name = $handlerName;
        Path = "*.php";
        Verb = "*";
        Modules = "FastCgiModule";
        scriptProcessor=$php;
        resourceType='Either' 
    }
}

###############################################################
# Configure the FastCGI Setting
###############################################################
# Set the max request environment variable for PHP
$configPath = "system.webServer/fastCgi/application[@fullPath='$php']/environmentVariables/environmentVariable"
$config = Get-WebConfiguration $configPath
if (!$config) {
    $configPath = "system.webServer/fastCgi/application[@fullPath='$php']/environmentVariables"
    Add-WebConfiguration $configPath -Value @{ 'Name' = 'PHP_FCGI_MAX_REQUESTS'; Value = 10050 }
}

# Configure the settings
# Available settings: 
#     instanceMaxRequests, monitorChangesTo, stderrMode, signalBeforeTerminateSeconds
#     activityTimeout, requestTimeout, queueLength, rapidFailsPerMinute, 
#     flushNamedPipe, protocol   
$configPath = "system.webServer/fastCgi/application[@fullPath='$php']"
Set-WebConfigurationProperty $configPath -Name instanceMaxRequests -Value 10000
Set-WebConfigurationProperty $configPath -Name monitorChangesTo -Value 'C:\tools\php\php.ini'

# Restart IIS to load new configs.
invoke-command -scriptblock {iisreset /restart }
