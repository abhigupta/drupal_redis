<?php

/**
 * Redis cache engine.
 *
 * http://code.google.com/p/redis/
 */

//
//function page_fast_cache() {
//	return $this->fast_cache;
//}

function dredis_get($key, $table = 'cache') {

	if($redis = dredis_connect($table)){
		$cache = $redis->get(dredis_key($key, $table));
		drupal_set_message('dredis_get Get Key : ' . urldecode(dredis_key($key, $table)));
		return $cache;
	}
	return FALSE;
}

function dredis_set($key, $value, $expire = 0, $table = 'cache') {

	if($redis = dredis_connect($table)) {
		$full_key = dredis_key($key, $table);
		$redis->set($full_key, $value);

		// Set expiry date if specified
		if($expire) {
			$redis->setTimeout($full_key, $expire);
		}
		else if($expire == -1) {
			$redis->sAdd($table . ':temporary', $full_key);
		}
	}
	// Create new cache object.
	//    $cache = new stdClass;
	//    $cache->cid = $key;
	//    $cache->created = time();
	//    $cache->expire = $expire;
	//    $cache->headers = $headers;
	//    $cache->data = $value;
	//    $cache->serialized = 0;
	//
	//    $cache = serialize($cache);
	//    assert($cache != NULL);
	//
	//    assert(strlen($key) > 0);

	//    if ($this->lock()) {
	//      if (!$this->settings['bin_per_db']) {
	//        // Get lookup table to be able to keep track of bins.
	//        $lookup = $this->redis->get($this->lookup);
	//
	//        // If the lookup table is empty, initialize table.
	//        if (strlen($lookup) == 0) {
	//          $lookup = array();
	//        }
	//        else {
	//          $lookup = unserialize($lookup);
	//          assert(is_array($lookup));
	//        }
	//
	//        // Set key to 1 so we can keep track of the bin.
	//        $lookup[$key] = $expire;
	//      }
	//
	//      // Attempt to store full key and value.
	//      $ret = $this->redis->set($this->key($key), $cache);
	//      // Set Expire time for key if it is specificed
	//      // Meaning $expire !== CACHE_PERMANENT (0) or CACHE_TEMPORARY(-1)
	//      if($expire) {
	//        $this->redis->expireat($this->key($key), $expire);
	//      }
	//      assert($ret == 'OK');
	//      if ($ret != 'OK') {
	//        unset($lookup[$key]);
	//        $return = FALSE;
	//      }
	//      else {
	//        // Update static cache.
	//        parent::set($key, $cache);
	//        $return = TRUE;
	//      }
	//
	//      if (!$this->settings['bin_per_db']) {
	//        // Resave the lookup table (even on failure).
	//        /// @todo Use Redis lists here.
	//        $ret = $this->redis->set($this->lookup, serialize($lookup));
	//        assert($ret == 'OK');
	//      }
	//
	//      // Remove lock.
	//      $this->unlock();
	//
	//      return $return;
	//    }
	//
	//    return FALSE;
}

function dredis_delete($cid = '*', $wildcard = FALSE, $table = 'cache') {

	if($redis = dredis_connect($table)) {
		if ($wildcard) {
			if ($cid == '*') {
				$redis->flushDB();
			}
			else {
				//Delete all the keys begging with $cid using wildcard
				$allKeys = $redis->getKeys(dredis_key($cid . '*', $table));
				foreach($allKeys as $key){
					$redis->delete($key);
					$redis->sRemove($table . ':temporary', $key);
				}
			}
		}
		else {
			$redis->delete(dredis($key, $table));
			$redis->sRemove($table . ':temporary', $key);
		}
	}

}

/**
 * Delete temporary keys from the table
 *
 * @param $table
 *     Name of the table to remove temporary keys.
 */
function dredis_flush($table = 'cache_page') {

	if($redis = dredis_connect($table)) {
		// Get all the keys from temporary set.
		$keys = $redis->sMembers($table .':temporary');
		//$delete_keys = "'" . implode("', '", $keys) . "'";
		foreach($keys as $key) {
			$redis->delete($key);
			drupal_set_message('dredis_flush Deleting Key : ' . $key);
		}
		$redis->delete($table .':temporary');
		//  		$redis->delete($delete_keys)
	}
}

//function lock() {
//	if ($this->settings['shared']) {
//		// Lock once by trying to add lock file, if we can't get the lock, we will loop
//		// for 3 seconds attempting to get lock. If we still can't get it at that point,
//		// then we give up and return FALSE.
//		if (!$this->redis->set($this->lock, '', TRUE)) {
//			$time = time();
//			while (!$this->redis->set($this->lock, '', TRUE)) {
//				if (time() - $time >= 3) {
//					return FALSE;
//				}
//			}
//		}
//		return TRUE;
//	}
//	return TRUE;
//}
//
//function unlock() {
//	if ($this->settings['shared']) {
//		$ret = $this->redis->delete($this->lock);
//		assert((int)$ret);
//		return $ret;
//	}
//	return TRUE;
//}

function dredis_connect($table = 'cache') {
	static $redis;

	$server = variable_get('redis_servers', array('127.0.0.1:6379'));
	list($host, $port) = explode(':', $server[0]);

	if(is_null($redis)){
		/// @todo Add redis as static variable. Don't reconnect for every cache bin.
		$redis = new Redis();
	}

	if (!$redis->connect($host, $port)) {
		//_cache_early_watchdog('cache', 'Unable to connect to Redis server %host:%port', array('%host' => $host, '%port' => $port), WATCHDOG_ERROR);
		exit('Could Not Connect');
		return FALSE;
	}

	// Change redis database if not default (0) specified.
	if ($dbid = dredis_db($table)) {
		if (!$redis->select($dbid)) {
			return FALSE;
		}
	}


	//    if (variable_get('redis_cache_clear_all', FALSE) == TRUE) {
	//      /// @todo Add ability to clear only specified Redis database. e.g. when we don't want to clear cache_form bin.
	//      $this->redis->flushall();
	//    }

	return $redis;
}

//function close() {
//	$this->redis->close();
//}

/**
 * Statistics information.
 * @todo
 */
/*
 function stats() {
 $stats = array(
 'uptime' => time(),
 'bytes_used' => 0,
 'bytes_total' => 0,
 'gets' => 0,
 'sets' => 0,
 'hits' => 0,
 'misses' => 0,
 'req_rate' => 0,
 'hit_rate' => 0,
 'miss_rate' => 0,
 'set_rate' => 0,
 );
 return $stats;
 }
 */

/**
 * Get the full key of the item.
 *
 * @param string $key
 *   The key to set.
 * @param string $bin = 'cache'
 *   The cache table name
 * @return string
 *   Returns the full key of the cache item.
 */
function dredis_key($key, $bin = 'cache') {
	static $prefix;
	// redis_key_prefix can be set in settings.php to support site namespaces
	// in a multisite environment.
	if (empty($prefix)) {
		$prefix = variable_get('redis_key_prefix', '');
	}
	$full_key = (!empty($prefix) ? $prefix. ':' : '') . $bin . ':' . $key;

	return urlencode($full_key);
}

/**
 * Get the DB Number for a Bin.
 *
 * @param string $table = 'cache'
 *   The cache table name
 * @return int
 *   Returns the number of DB to be use.
 */
function dredis_db($table = 'cache') {
	static $mapping = array();

	if (isset($mapping[$table])){
		$return = $mapping[$table];
	}

	if (!count($mapping)) {
		//TODO: Store mapping in redis itself, use variable table as backup
		drupal_set_message('Mappings are being Regenrated : ' . var_export($mapping, true));
		$mapping = variable_get('redis_bins', array());
		drupal_set_message('Mappings are being Regenrated : ' . var_export($mapping, true));

		if(count($mapping) && isset($mapping[$table])) {
			$return = $mapping[$table];
			drupal_set_message('Mappings returned from DB : ' . $return);
		}
		else {
			$core = array('cache', 'cache_block', 'cache_form', 'cache_filter', 'cache_page', 'cache_menu');
			//			$cache_tables = array_merge(module_invoke_all('flush_caches'), $core);
			$cache_tables = $core;

			sort($cache_tables);
			$max = count($cache_tables);
			for($i = 1; $i <= $max; $i++){
				$mapping[$cache_tables[($i-1)]] = $i;
			}
			variable_set('redis_bins', $mapping);
			drupal_set_message('Mappings regenrated : ' . var_export($mapping, true));

			$return = isset($mapping[$table]) ? $mapping[$table] : 0;
		}
	}
//	drupal_set_message("{$table} - {$return}");
	return isset($return) ? $return : 0;
}