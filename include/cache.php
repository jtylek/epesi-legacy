<?php
/**
 * @author    Adam Bukowski <abukowski@telaxus.com>
 * @version   1.0
 * @copyright Copyright &copy; 2015, Telaxus LLC
 * @license   MIT
 * @package   epesi-base
 */
defined("_VALID_ACCESS") || die('Direct access forbidden');

use Phpfastcache\CacheManager;
use Phpfastcache\Config\ConfigurationOption;

class Cache
{
    protected static $cache_object;

    /** Driver actually in use, for diagnostics - see active_driver(). */
    protected static $active_driver;

    /** Current cache generation, part of every key - see clear(). Loaded lazily. */
    protected static $generation;

    /** Lifetime of the stored generation token. Losing it early (eviction, a memcached
     *  restart) is safe - a fresh one is minted, which only invalidates the cache - so this
     *  just bounds how often that happens on its own. 30 days is also memcached's ceiling
     *  for a relative TTL. */
    const GENERATION_TTL = 2592000;

    public static function init()
    {
        foreach (self::driver_chain() as $driver) {
            try {
                self::$cache_object = CacheManager::getInstance($driver, self::config_for($driver));
                self::$active_driver = $driver;
                break;
            } catch (Exception) {
                // Covers phpfastcache's own PhpfastcacheDriverCheckException (it extends
                // Exception), which is what a pinned driver with a missing extension
                // throws - so that case falls through to the next driver in the chain
                // rather than taking the install down.
            }
        }
        if (!self::$cache_object) {
            throw new Exception('No valid cache driver');
        }
    }

    /**
     * Drivers to try, in order.
     *
     * CACHE_TYPE names one explicitly; anything else still gets the fallback chain
     * appended, because a pinned driver whose extension turns out to be missing should
     * degrade to a working cache rather than take the whole install down. That failure
     * mode is not hypothetical: this app spent an unknown stretch silently running its
     * Roundcube cache on 'db' because a Memcache/Memcached class check picked the wrong
     * one (item 1.5, fixed 2026-08-31).
     *
     * 'auto' is the historical chain exactly: memcached only when a server is configured,
     * then the local drivers. Sqlite is new to the chain and sits ahead of Files - it is
     * a real win for installs with no memcached and no APCu, which before this had
     * nothing but one file per cache entry.
     */
    protected static function driver_chain()
    {
        $networked = array('memcached' => 'Memcached', 'memcache' => 'Memcache', 'redis' => 'Redis', 'predis' => 'Predis');
        $local     = array('apcu' => 'Apcu', 'zendshm' => 'Zendshm', 'sqlite' => 'Sqlite', 'files' => 'Files');
        $type = strtolower((string) CACHE_TYPE);

        $chain = array();
        if (isset($networked[$type])) {
            if (!CACHE_SERVER) trigger_error('CACHE_TYPE is "'.$type.'" but CACHE_SERVER is empty', E_USER_WARNING);
            $chain[] = $networked[$type];
        } elseif (isset($local[$type])) {
            $chain[] = $local[$type];
        } elseif ($type !== 'auto' && $type !== '') {
            trigger_error('Unknown CACHE_TYPE "'.$type.'", falling back to auto', E_USER_WARNING);
        }

        if (!$chain && CACHE_SERVER) {
            // 'auto'. class_exists() rather than the driver name, because both extensions
            // provide a class of their own name and only one is usually present.
            if (class_exists('Memcached')) $chain[] = 'Memcached';
            elseif (class_exists('Memcache')) $chain[] = 'Memcache';
        }

        // Apc/Xcache no longer exist as of phpfastcache 9.x (both extensions are long dead).
        foreach (array('Apcu', 'Zendshm', 'Sqlite', 'Files') as $fallback) {
            if (!in_array($fallback, $chain)) $chain[] = $fallback;
        }
        return $chain;
    }

    /** Which driver init() settled on. Worth reading rather than inferring from the
     *  extension list - see AI-private/REFERENCE-optimization-opus-AI.md section 7 note 6. */
    public static function active_driver()
    {
        return self::$active_driver;
    }

    // Each driver's Config class only accepts its own specific set of properties
    // (e.g. Memcache(d)'s "servers", Files' "path"/"securityKey"), so build the
    // exact class per driver rather than a single shared config array.
    protected static function config_for($driver)
    {
        $defaultTtl = 86400; // 24h
        switch ($driver) {
            case 'Memcached':
            case 'Memcache':
                $srv = explode(':', CACHE_SERVER, 2);
                $configClass = $driver === 'Memcached'
                    ? \Phpfastcache\Drivers\Memcached\Config::class
                    : \Phpfastcache\Drivers\Memcache\Config::class;
                return new $configClass([
                    'defaultTtl' => $defaultTtl,
                    'servers' => [['host' => $srv[0], 'port' => (int)($srv[1] ?? 11211)]],
                ]);
            case 'Redis':
            case 'Predis':
                $srv = explode(':', CACHE_SERVER, 2);
                $configClass = $driver === 'Redis'
                    ? \Phpfastcache\Drivers\Redis\Config::class
                    : \Phpfastcache\Drivers\Predis\Config::class;
                return new $configClass([
                    'defaultTtl' => $defaultTtl,
                    'host' => $srv[0] ?: '127.0.0.1',
                    'port' => (int)($srv[1] ?? 6379),
                ]);
            case 'Sqlite':
                // Same directory as the Files driver: regenerable cache output belongs in
                // TEMP_DIR, not DATA_DIR (see include/config.php and the 20260829 patch).
                return new \Phpfastcache\Drivers\Sqlite\Config([
                    'path' => EPESI_LOCAL_DIR . '/' . TEMP_DIR . '/cache',
                    'securityKey' => INSTALLATION_ID,
                    'defaultTtl' => $defaultTtl,
                ]);
            case 'Files':
                return new \Phpfastcache\Drivers\Files\Config([
                    'path' => EPESI_LOCAL_DIR . '/' . TEMP_DIR . '/cache',
                    'securityKey' => INSTALLATION_ID,
                    'defaultTtl' => $defaultTtl,
                ]);
            default:
                return new ConfigurationOption(['defaultTtl' => $defaultTtl]);
        }
    }

    public static function get($name, $default = null)
    {
        $ret = self::$cache_object->getItem(self::key($name));
        if(is_null($ret->get())) return $default;
        return $ret->get();
    }

    public static function set($name, $value,$expiration_seconds=0)
    {
        $ret = self::$cache_object->getItem(self::key($name));
        $ret->set($value);
        if($expiration_seconds>0) $ret->expiresAfter($expiration_seconds);
        self::$cache_object->save($ret);
    }

    /**
     * With a name, drops that one entry. Without, empties this install's whole cache.
     *
     * The whole-cache case switches to a new generation rather than calling the driver's
     * own clear(), because on every shared backend that is a server-wide flush: flush() on
     * memcached, FLUSHDB on redis, apcu_clear_cache()/zend_shm_cache_clear() for the whole
     * PHP process pool. Sessions can sit on that same memcached (SESSION_TYPE=memcache, and
     * CACHE_SERVER falls back to MEMCACHE_SESSION_SERVER), so every module install, admin
     * Clear Cache or cache:rebuild used to log out every user of every install on the server.
     * The old generation's entries are never read again and age out through their TTL; a
     * request that already read the old generation keeps using it until it ends.
     *
     * Files and Sqlite live under this install's own TEMP_DIR, so a real clear is safe
     * there, and it frees the disk the old generation would otherwise hold.
     */
    public static function clear($name=null)
    {
        if ($name) {
            self::$cache_object->deleteItem(self::key($name));
            return;
        }
        if (in_array(self::$active_driver, array('Files', 'Sqlite'))) {
            self::$cache_object->clear();
        }
        self::new_generation();
    }

    protected static function key($name)
    {
        return 'epesi_' . INSTALLATION_ID . '_' . self::generation() . '_' . $name;
    }

    protected static function generation_key()
    {
        return 'epesi_' . INSTALLATION_ID . '_generation';
    }

    protected static function generation()
    {
        if (self::$generation === null) {
            $stored = self::$cache_object->getItem(self::generation_key())->get();
            if (is_string($stored) && $stored !== '') self::$generation = $stored;
            else self::new_generation();
        }
        return self::$generation;
    }

    /** Random rather than a counter: a lost counter would restart at a value whose old
     *  entries may still be live, a lost random token never matches anything. */
    protected static function new_generation()
    {
        self::$generation = bin2hex(random_bytes(4));
        $item = self::$cache_object->getItem(self::generation_key());
        $item->set(self::$generation);
        $item->expiresAfter(self::GENERATION_TTL);
        self::$cache_object->save($item);
    }

}

Cache::init();
