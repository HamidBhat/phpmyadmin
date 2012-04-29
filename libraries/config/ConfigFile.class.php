<?php
/* vim: set expandtab sw=4 ts=4 sts=4: */
/**
 * Config file management
 *
 * @package PhpMyAdmin
 */

/**
 * Config file management class.
 * Stores its data in $_SESSION
 *
 * @package PhpMyAdmin
 */
class ConfigFile
{
    /**
     * Stores default PMA config from config.default.php
     * @var array
     */
    private $cfg;

    /**
     * Stores original PMA_Config object, not modified by user preferences
     * @var PMA_Config
     */
    private $orgCfgObject;

    /**
     * Stores allowed values for non-standard fields
     * @var array
     */
    private $cfgDb;

    /**
     * Keys which will be always written to config file
     * @var array
     */
    private $persistKeys = array();

    /**
     * Changes keys while updating config in {@link updateWithGlobalConfig()}
     * or reading by {@link getConfig()} or {@link getConfigArray()}
     * @var array
     */
    private $cfgUpdateReadMapping = array();

    /**
     * Key filter for {@link set()}
     * @var array|null
     */
    private $setFilter;

    /**
     * Instance id (key in $_SESSION array, separate for each server -
     * ConfigFile{server id})
     * @var string
     */
    private $id;

    /**
     * Result for {@link _flattenArray()}
     * @var array
     */
    private $_flattenArrayResult;

    /**
     * ConfigFile instance
     * @var ConfigFile
     */
    private static $_instance;

    /**
     * Private constructor, use {@link getInstance()}
     *
     */
    private function __construct()
    {
        // load default config values
        $cfg = &$this->cfg;
        include './libraries/config.default.php';
        $cfg['fontsize'] = '82%';

        // create PMA_Config to read config.inc.php values
        $this->orgCfgObject = new PMA_Config(CONFIG_FILE);

        // load additional config information
        $cfg_db = &$this->cfgDb;
        include './libraries/config.values.php';

        // apply default values overrides
        if (count($cfg_db['_overrides'])) {
            foreach ($cfg_db['_overrides'] as $path => $value) {
                PMA_array_write($path, $cfg, $value);
            }
        }

        $this->id = 'ConfigFile' . $GLOBALS['server'];
        if (!isset($_SESSION[$this->id])) {
            $_SESSION[$this->id] = array();
        }
    }

    /**
     * Returns class instance
     *
     * @return ConfigFile
     */
    public static function getInstance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new ConfigFile();
        }
        return self::$_instance;
    }

    /**
     * Returns PMA_Config without user preferences applied
     *
     * @return PMA_Config
     */
    public function getOrgConfigObj()
    {
        return $this->orgCfgObject;
    }

    /**
     * Sets names of config options which will be placed in config file even if
     * they are set to their default values (use only full paths)
     *
     * @param array $keys
     */
    public function setPersistKeys($keys)
    {
        // checking key presence is much faster than searching so move values
        // to keys
        $this->persistKeys = array_flip($keys);
    }

    /**
     * Returns flipped array set by {@link setPersistKeys()}
     *
     * @return array
     */
    public function getPersistKeysMap()
    {
        return $this->persistKeys;
    }

    /**
     * By default ConfigFile allows setting of all configuration keys, use
     * this method to set up a filter on {@link set()} method
     *
     * @param array|null $keys array of allowed keys or null to remove filter
     */
    public function setAllowedKeys($keys)
    {
        if ($keys === null) {
            $this->setFilter = null;
            return;
        }
        // checking key presence is much faster than searching so move values
        // to keys
        $this->setFilter = array_flip($keys);
    }

    /**
     * Sets path mapping for updating config in
     * {@link updateWithGlobalConfig()} or reading
     * by {@link getConfig()} or {@link getConfigArray()}
     * @var array
     */
    public function setCfgUpdateReadMapping(array $mapping)
    {
        $this->cfgUpdateReadMapping = $mapping;
    }

    /**
     * Resets configuration data
     */
    public function resetConfigData()
    {
        $_SESSION[$this->id] = array();
    }

    /**
     * Sets configuration data (overrides old data)
     *
     * @param array $cfg
     */
    public function setConfigData(array $cfg)
    {
        $_SESSION[$this->id] = $cfg;
    }

    /**
     * Sets config value
     *
     * @param string $path
     * @param mixed  $value
     * @param string $canonical_path
     */
    public function set($path, $value, $canonical_path = null)
    {
        if ($canonical_path === null) {
            $canonical_path = $this->getCanonicalPath($path);
        }
        // apply key whitelist
        if ($this->setFilter !== null && !isset($this->setFilter[$canonical_path])) {
            return;
        }
        // remove if the path isn't protected and it's empty or has a default
        // value
        if (!isset($this->persistKeys[$canonical_path])) {
            $default_value = $this->getDefault($canonical_path);
            // we need oryginal config values not overwritten by user
            // preferences to allow for overwriting options set in
            // config.inc.php with default values
            $instance_default_value = PMA_array_read(
                $canonical_path,
                $this->orgCfgObject->settings
            );
            if (($value === $default_value && (defined('PMA_SETUP')
                || $instance_default_value === $default_value))
                || (empty($value) && empty($default_value) && (defined('PMA_SETUP')
                || empty($current_global)))
            ) {
                PMA_array_remove($path, $_SESSION[$this->id]);
                return;
            }
        }
        PMA_array_write($path, $_SESSION[$this->id], $value);
    }

    /**
     * Flattens multidimensional array, changes indices to paths
     * (eg. 'key/subkey').
     * Used as array_walk() callback.
     *
     * @param mixed $value
     * @param mixed $key
     * @param mixed $prefix
     */
    private function _flattenArray($value, $key, $prefix)
    {
        // no recursion for numeric arrays
        if (is_array($value) && !isset($value[0])) {
            $prefix .= $key . '/';
            array_walk($value, array($this, '_flattenArray'), $prefix);
        } else {
            $this->_flattenArrayResult[$prefix . $key] = $value;
        }
    }

    /**
     * Returns default config in a flattened array
     *
     * @return array
     */
    public function getFlatDefaultConfig()
    {
        $this->_flattenArrayResult = array();
        array_walk($this->cfg, array($this, '_flattenArray'), '');
        $flat_cfg = $this->_flattenArrayResult;
        $this->_flattenArrayResult = null;
        return $flat_cfg;
    }

    /**
     * Updates config with values read from given array
     * (config will contain differences to defaults from config.defaults.php).
     *
     * @param array $cfg
     */
    public function updateWithGlobalConfig(array $cfg)
    {
        // load config array and flatten it
        $this->_flattenArrayResult = array();
        array_walk($cfg, array($this, '_flattenArray'), '');
        $flat_cfg = $this->_flattenArrayResult;
        $this->_flattenArrayResult = null;

        // save values map for translating a few user preferences paths,
        // should be complemented by code reading from generated config
        // to perform inverse mapping
        foreach ($flat_cfg as $path => $value) {
            if (isset($this->cfgUpdateReadMapping[$path])) {
                $path = $this->cfgUpdateReadMapping[$path];
            }
            $this->set($path, $value, $path);
        }
    }

    /**
     * Returns config value or $default if it's not set
     *
     * @param string $path
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get($path, $default = null)
    {
        return PMA_array_read($path, $_SESSION[$this->id], $default);
    }

    /**
     * Returns default config value or $default it it's not set ie. it doesn't
     * exist in config.default.php ($cfg) and config.values.php
     * ($_cfg_db['_overrides'])
     *
     * @param string $canonical_path
     * @param mixed  $default
     *
     * @return mixed
     */
    public function getDefault($canonical_path, $default = null)
    {
        return PMA_array_read($canonical_path, $this->cfg, $default);
    }

    /**
     * Returns config value, if it's not set uses the default one; returns
     * $default if the path isn't set and doesn't contain a default value
     *
     * @param string $path
     * @param mixed  $default
     *
     * @return mixed
     */
    public function getValue($path, $default = null)
    {
        $v = PMA_array_read($path, $_SESSION[$this->id], null);
        if ($v !== null) {
            return $v;
        }
        $path = $this->getCanonicalPath($path);
        return $this->getDefault($path, $default);
    }

    /**
     * Returns canonical path
     *
     * @param string $path
     *
     * @return string
     */
    public function getCanonicalPath($path)
    {
        return preg_replace('#^Servers/([\d]+)/#', 'Servers/1/', $path);
    }

    /**
     * Returns config database entry for $path ($cfg_db in config_info.php)
     *
     * @param string $path
     * @param mixed  $default
     *
     * @return mixed
     */
    public function getDbEntry($path, $default = null)
    {
        return PMA_array_read($path, $this->cfgDb, $default);
    }

    /**
     * Returns server count
     *
     * @return int
     */
    public function getServerCount()
    {
        return isset($_SESSION[$this->id]['Servers'])
            ? count($_SESSION[$this->id]['Servers'])
            : 0;
    }

    /**
     * Returns server list
     *
     * @return array|null
     */
    public function getServers()
    {
      return isset($_SESSION[$this->id]['Servers'])
          ? $_SESSION[$this->id]['Servers']
          : null;
    }

    /**
     * Returns DSN of given server
     *
     * @param integer $server
     *
     * @return string
     */
    function getServerDSN($server)
    {
        if (!isset($_SESSION[$this->id]['Servers'][$server])) {
            return '';
        }

        $path = 'Servers/' . $server;
        $dsn = $this->getValue("$path/extension") . '://';
        if ($this->getValue("$path/auth_type") == 'config') {
            $dsn .= $this->getValue("$path/user");
            if (!$this->getValue("$path/nopassword")) {
                $dsn .= ':***';
            }
            $dsn .= '@';
        }
        if ($this->getValue("$path/connect_type") == 'tcp') {
            $dsn .= $this->getValue("$path/host");
            $port = $this->getValue("$path/port");
            if ($port) {
                $dsn .= ':' . $port;
            }
        } else {
            $dsn .= $this->getValue("$path/socket");
        }
        return $dsn;
    }

    /**
     * Returns server name
     *
     * @param int $id
     *
     * @return string
     */
    public function getServerName($id)
    {
        if (!isset($_SESSION[$this->id]['Servers'][$id])) {
            return '';
        }
        $verbose = $this->get("Servers/$id/verbose");
        if (!empty($verbose)) {
            return $verbose;
        }
        $host = $this->get("Servers/$id/host");
        return empty($host) ? 'localhost' : $host;
    }

    /**
     * Removes server
     *
     * @param int $server
     */
    public function removeServer($server)
    {
        if (!isset($_SESSION[$this->id]['Servers'][$server])) {
            return;
        }
        $last_server = $this->getServerCount();

        for ($i = $server; $i < $last_server; $i++) {
            $_SESSION[$this->id]['Servers'][$i]
                = $_SESSION[$this->id]['Servers'][$i + 1];
        }
        unset($_SESSION[$this->id]['Servers'][$last_server]);

        if (isset($_SESSION[$this->id]['ServerDefault'])
            && $_SESSION[$this->id]['ServerDefault'] >= 0
        ) {
            unset($_SESSION[$this->id]['ServerDefault']);
        }
    }

    /**
     * Returns config file path, relative to phpMyAdmin's root path
     *
     * @return string
     */
    public function getFilePath()
    {
        // Load paths
        if (!defined('SETUP_CONFIG_FILE')) {
            include_once './libraries/vendor_config.php';
        }

        return SETUP_CONFIG_FILE;
    }

    /**
     * Returns configuration array (full, multidimensional format)
     *
     * @return array
     */
    public function getConfig()
    {
        $c = $_SESSION[$this->id];
        foreach ($this->cfgUpdateReadMapping as $map_to => $map_from) {
            PMA_array_write($map_to, $c, PMA_array_read($map_from, $c));
            PMA_array_remove($map_from, $c);
        }
        return $c;
    }

    /**
     * Returns configuration array (flat format)
     *
     * @return array
     */
    public function getConfigArray()
    {
        $this->_flattenArrayResult = array();
        array_walk($_SESSION[$this->id], array($this, '_flattenArray'), '');
        $c = $this->_flattenArrayResult;
        $this->_flattenArrayResult = null;

        $persistKeys = array_diff(
            array_keys($this->persistKeys),
            array_keys($c)
        );
        foreach ($persistKeys as $k) {
            $c[$k] = $this->getDefault($k);
        }

        foreach ($this->cfgUpdateReadMapping as $map_to => $map_from) {
            if (!isset($c[$map_from])) {
                continue;
            }
            $c[$map_to] = $c[$map_from];
            unset($c[$map_from]);
        }
        return $c;
    }
}
?>
