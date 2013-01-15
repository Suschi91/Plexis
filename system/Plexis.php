<?php
/**
 * Plexis Content Management System
 *
 * @file        System/Plexis.php
 * @copyright   2011-2012, Plexis Dev Team
 * @license     GNU GPL v3
 * @package     System
 */

/**
 * First, import some classes into scope, so we can 
 * directly call the class name without having to 
 * specify the namespace everytime ( think C# :D )
 */
use Core\AutoLoader;
use Core\Benchmark;
use Core\Config;
use Core\Database;
use Core\DatabaseConnectError;
use Core\Dispatch;
use Core\Logger;
use Core\NotFoundException;
use Core\Request;
use Core\Response;
use Core\Router;
use Library\Auth;
use Library\Template;
use Library\View;
use Wowlib\Wowlib;

/**
 * The backend controller is the main method for running the Application
 *
 * @author      Steven Wilson 
 * @package     System
 */
class Plexis
{
    /**
     * Internal var that prevents plexis from running twice
     * @var bool
     */
    private static $isRunning = false;
    
    /**
     * Holds the current module object, false if the module isn't yet dispatched
     * @var Core\Module|bool
     */
    public static $module = false;
    
    /**
     * Holds the plexis Logger object
     * @var \Core\Logger
     */
    protected static $log;
    
    /**
     * The Wowlib object
     * @var \Wowlib\Realm
     */
    protected static $realm = null;
    
    /**
     * An array of helpers that have been loaded
     * @var string[]
     */
    protected static $helpers = array();
    
    /**
     * An array of installed plugins
     * @var string[]
     */
    protected static $plugins = array();
    
    /**
     * Certain modules may not want the template to render.
     * @var bool
     */
    protected static $renderTemplate = true;
    
    
    /**
     * Main method for running the Plexis application
     *
     * @return void
     */
    public static function Run()
    {
        // Make sure only one instance of the cms is running at a time
        if(self::$isRunning) return;
        
        // We are now running
        self::$isRunning = true;
        
        /** The URL to get to the root of the website (HTTP_HOST + webroot) */
        define('SITE_URL', ( MOD_REWRITE ) ? Request::BaseUrl() : Request::BaseUrl() .'/?uri=');
        
        // Set default theme path (temporary)
        Template::SetThemePath( path(ROOT, "themes"), 'default' );
        
        // Init the plexis config files
        self::LoadConfigs();
        
        // Load Plugins
        self::LoadPlugins();
        
        // Load the Wowlib
        if(self::$realm === null)
            self::LoadWowlib(false);
        
        // Init the database connection, we check to see if it exists first, because
        // a plugin might have already loaded it
        if(Database::GetConnection('DB') === false)
            self::LoadDBConnection();
        
        // Start the Client Auth class
        Auth::Init();
        
        // Load our controller etc etc
        Router::HandleRequest();
        
        // Do we render the template?
        if(self::$renderTemplate)
            Template::Render();
    }
    
    /**
     * Displays the 404 page not found page
     *
     * Calling this method will clear all current output, render the 404 page
     * and kill all current running scripts. No code following this method
     * will be executed
     *
     * @return void
     */
    public static function Show404()
    {
        // Load the 404 Error module
        $Module = Router::Forge('error/404');
        if($Module == false)
            die('404');
        $Module->invoke();
        die;
    }
    
    /**
     * Displays the 403 "Forbidden"
     *
     * Calling this method will clear all current output, render the 403 page
     * and kill all current running scripts. No code following this method
     * will be executed
     *
     * @return void
     */
    public static function Show403()
    {
        // Load the 403 Error module
        $Module = Router::Forge('error/403');
        if($Module == false)
            die('403');
        $Module->invoke();
        die;
    }
    
    /**
     * Displays the site offline page
     *
     * Calling this method will clear all current output, render the site offline
     * page and kill all current running scripts. No code following this method
     * will be executed
     *
     * @param string $message The meesage to also be displayed with the
     *   Site Offline page.
     * @return void
     */
    public static function ShowSiteOffline($message = null)
    {
        // Load the 403 Error module
        $Module = Router::Forge('error/offline');
        if($Module == false)
            die('Site is currently unavailable.');
        $Module->invoke();
        die;
    }
    
    /**
     * Returns the Realm Object
     *
     * @return \Wowlib\Realm
     */
    public static function GetRealm()
    {
        return self::$realm;
    }
    
    /**
     * Returns an array of installed plugins
     *
     * @return string[]
     */
    public static function ListPlugins()
    {
        return self::$plugins;
    }
    
    /**
     * Returns whether or not a plugin is installed and running
     *
     * @param string $name The name of the plugin
     *
     * @return bool
     */
    public static function PluginInstalled($name)
    {
        return in_array($name, self::$plugins);
    }
    
    /**
     * Sets whether plexis should render the full template or not
     *
     * @param bool $bool Render the template?
     *
     * @return void
     */
    public static function RenderTemplate($bool = true)
    {
        if(!is_bool($bool)) return;
        
        self::$renderTemplate = $bool;
    }
    
    /**
     * Loads the requested helper name
     *
     * @param string $name The helper name to load (no file extension)
     *
     * @return bool Returns false if the helper doesnt exist, true otherwise
     */
    public static function LoadHelper($name)
    {
        // If we already loaded this helper, return true
        $name = strtolower($name);
        if(in_array($name, self::$helpers))
            return true;
            
        // Build path
        $path = path( SYSTEM_PATH, 'helpers', $name .'.php' );
        if(file_exists($path))
        {
            require_once $path;
            
            // Add the helper to the list
            self::$helpers[] = $name;
            return true;
        }
        return false;
    }
    
    /**
     * Internal method for loading the Plexis DB connection
     *
     * @param bool $showOffline If set to false, the Site Offline page will 
     *   not be rendered if the plexis database connection is offline
     *
     * @return \Database\Driver
     */
    public static function LoadDBConnection($showOffline = true)
    {
        $conn = false;
        try {
            $conn = Database::Connect('DB', Config::GetVar('PlexisDB', 'DB'));
        }
        catch( DatabaseConnectError $e ) {
            if($showOffline)
            {
                $message = $e->getMessage();
                self::ShowSiteOffline('Plexis database offline');
            }
        }
        
        return $conn;
    }
    
    /**
     * Internal method for loading, and running all plugins
     *
     * @return void
     */
    protected static function LoadPlugins()
    {
        // Include our plugins file, and get the size
        include path( SYSTEM_PATH, 'config', 'plugins.php' );
        $OrigSize = sizeof($Plugins);
        
        // Loop through and run each plugin
        $i = 0;
        foreach($Plugins as $name)
        {
            $file = path( SYSTEM_PATH, 'plugins', $name .'.php');
            if(!file_exists($file))
            {
                // Remove the plugin from the list
                unset($Plugins[$i]);
                continue;
            }
            
            // Construct the plugin class
            include $file;
            $className = "Plugin\\". $name;
            new $className();
            
            // Add the plugin to the list of installed plugins
            self::$plugins[] = $name;
            $i++;
        }
        
        // If we had to remove plugins, then save the plugins file
        if(sizeof($Plugins) != $OrigSize)
        {
            $file = path( SYSTEM_PATH, 'config', 'plugins.php' );
            $source = "<?php\n\$Plugins = ". var_export($Plugins, true) .";\n?>";
            file_put_contents($file, $source);
        }
    }
    
    /**
     * Internal method for loading the wowlib
     *
     * @param bool $showOffline If set to false, the Site Offline page will 
     *   not be rendered if the realm database connection is offline
     *
     * @return void
     */
    protected static function LoadWowlib($showOffline = true)
    {
        // Load the wowlib class file
        require path( SYSTEM_PATH, "wowlib", "wowlib.php" );
        
        // Try to init the wowlib
        $message = null;
        try {
            Wowlib::Init( Config::GetVar('emulator', 'Plexis') );
            self::$realm = Wowlib::GetRealm(0, Config::GetVar('RealmDB', 'DB'));
        }
        catch( Exception $e ) {
            // Template::Message('error', 'Wowlib offline: '. $e->getMessage());
            $message = $e->getMessage();
            self::$realm = false;
        }
        
        // If the realm is offline, show the site offline screen
        if(self::$realm === false)
        {
            if($showOffline)
            {
                if(empty($message)) $message = "Realm Database Offline";
                self::ShowSiteOffline($message);
            }
        }
    }
    
    /**
     * Internal method for loading the plexis config files
     *
     * @return void
     */
    protected static function LoadConfigs()
    {
        // Import the Versions file
        require path(SYSTEM_PATH, "Versions.php");
        
        // Load the Plexis Config file
        $file = path(SYSTEM_PATH, "config", "config.php");
        Config::Load($file, 'Plexis');
        
        // Load Database config file
        $file = path(SYSTEM_PATH, "config", "database.php");
        Config::Load($file, 'DB', 'DB_Configs');
        
        /** Define whether we are debugging or not */
        define('DEBUGGING', (Config::GetVar('debugging', 'Plexis') && Request::Query('debug', false) !== false));
        
        // Build path to our log
        if( DEBUGGING )
        {
            $uri = str_replace('/', '_',  Request::Query('uri'));
            if(empty($uri) || $uri == '_')
                $uri = 'index';
            $logPath = path( SYSTEM_PATH, 'logs', 'debug', 'debug_'. $uri .'_'. time() .'.log' );
        }
        else
            $logPath = path( SYSTEM_PATH, 'logs', 'plexis.log' );
        
        // Init the new logger
        self::$log = new Logger($logPath, 'Debug');
        
        // If debugging, we set the log level
        if( DEBUGGING )
            self::$log->setLogLevel( Logger::DEBUG );
        else
            self::$log->setLogLevel( Config::GetVar('log_level', 'Plexis') );
    }
}