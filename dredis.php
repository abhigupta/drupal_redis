<?php
// $Id$

/**
 * Redis cache engine.
 *
 * http://code.google.com/p/redis/
 */

//
//function page_fast_cache() {
//	return $this->fast_cache;
//}

/**
 * Retrieve value from Redis based on specified Key.
 *
 * @param $key
 *    Name of Key for which value is being fetched.
 * @param $table = 'cache'
 *    Name of Table/Bin to get results from
 *
 * @return Cache Data if found or FALSE
 */
function dredis_get($key, $table = 'cache') {

  $full_key = dredis_key($key, $table);
  if ($redis = dredis_connect($full_key)) {
    return $redis->get($full_key);
    //drupal_set_message('dredis_get Get Key : ' . urldecode(dredis_key($key, $table)));
  }
  return FALSE;
}

/**
 * Store the Key/Value pair in Redis
 *
 * If the $expire value is CACHE_TEMPORARY, this function stores the key
 * names in a Redis SET Labled $table:temporary for use during cache flushes
 *
 * @param $key
 *    The key or cid of the cache value
 * @param $value
 *    The value to save with specified key (serialized)
 * @param $expire
 *    The time to keep the Key value in the Redis
 *    $expire = 0 => Never Delete the Key
 *    $expire = -1 => Key is temporary
 *
 * @param $table = 'cache'
 *    The name of table / bin to store the Key
 */
function dredis_set($key, $value, $expire = 0, $table = 'cache') {

  $full_key = dredis_key($key, $table);
  if ($redis = dredis_connect($full_key)) {

    $redis->set($full_key, $value);
    //drupal_set_message('dredis_set Setting Key : ' . urldecode($full_key));

    // Set expiry date if expire != CACHE_PERMANENT
    if ($expire > 0) {
      $redis->setTimeout($full_key, $expire);
      //      $redis->sAdd($table .':timed', $full_key);
      //drupal_set_message('dredis_set Setting Expiry to ' . $expire . ' for: ' . urldecode($full_key));
    }
    // Save the Key to Temporary Set if the Key is CACHE_TEMPORARY
    // This will be cleared during dredis_flush_temporary
    elseif ($expire == -1) {
      $redis->sAdd($table .':temporary', $full_key);
    }
  }
}

/**
 * Delete cache entries from Redis
 *
 * @param $cid = '*'
 *    Unique ID of the key to delete from DB
 *    If * is passed with $wildcard = TRUE then everything inside the Redis DB
 *    is flushed.
 * @param $wildcard = FALSE
 *    Set to TRUE to clean keys using wildcards
 * @param $table = 'cache'
 *    Name of table where the key is stored
 */
function dredis_delete($cid = '*', $wildcard = FALSE, $table = 'cache') {
  //  drupal_set_message("cid: {$cid} , table: {$table}, wildcard: {$wildcard}");

  if ($wildcard) {
    $key_id = ($cid === '*') ? '' : $cid;
    $wildcard_key = dredis_key($key_id, $table) .'*';

    // Get a list of all keys that satisfy the wildcard
    $servers = dredis_connect($table, TRUE);
    $db_id = variable_get('redis_db_id', 1);
    foreach ($servers as $redis) {
      $redis->select($db_id);
      $keys = (array)$redis->getKeys($wildcard_key);
      $redis->delete($keys);
      foreach ($keys as $key) {
        $redis->sRemove($table .':temporary', $key);
      }
    }
  }
  else {
    $key = dredis_key($cid, $table);
    if($redis = dredis_connect($key)) {
    	$redis->delete($key);
    	//Delete Keys from temporary set if present
    	$redis->sRemove($table .':temporary', $key);
    }
  }
}

/**
 * Delete all the temporary keys from a table
 *
 * @param $table
 *     Name of the table to remove temporary keys.
 */
function dredis_flush($table = 'cache_page') {

  //	drupal_set_message("Flush table: {$table}");
  if ($servers = dredis_connect($table, TRUE)) {
    $db_id = variable_get('redis_db_id', 1);
    foreach ($servers as $redis) {
      if ($redis->select($db_id)) {
        // Get all the keys from temporary set.
        $keys = $redis->sMembers($table .':temporary');
        $redis->delete($keys);
        //drupal_set_message('dredis_flush Deleting Key : ' . $key);
        $redis->delete($table .':temporary');
      }
    }
  }
}

//function dredis_lock($key = '') {
//    if(empty($key)) {
//    	return FALSE;
//    }

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

/**
 * Connect to redis Database and Select the appropriate DB.
 *
 * @param $key
 *    Full Key
 *
 * @return Redis
 *    Return the Redis object.
 */
function dredis_connect($key, $return_all = FALSE) {
  static $redis = array();
  static $db_id;

  if (!count($redis)) {
    $servers = variable_get('redis_servers', array('127.0.0.1:6379'));
    $total_servers = count($servers);
    //    drupal_set_message('Total Redis Servers : ' . "$total_servers");
    // Loop through all redis servers and add them to $redis array
    for ($i = 0; $i < $total_servers; $i++) {
      list($host, $port) = explode(':', $servers[$i]);
      $tmp_server = new Redis();
      try {
        $tmp_server->connect($host, $port);
        $redis[$i] = $tmp_server;
        //    			drupal_set_message('Connected to Redis server at : ' . "$host - $port");
      }
      catch(RedisException$e) {
        drupal_set_message('Caught Exception: '. $e->getMessage());
      }
    }
  }

  if ($active_servers = count($redis)) {

    if ($return_all) {
      return $redis;
    }

    $server_id = 0;
    if ($active_servers > 1) {
      $server_id = crc32($key) % $active_servers;
      //  		drupal_set_message('Connecting to Redis server number : ' . $server_id);
      //  		drupal_set_message('CRC:' . crc32($key) . ' for Key: ' . $key);
    }

    if (empty($db_id)) {
      $db_id = variable_get('redis_db_id', 1);
    }

    if ($redis[$server_id]->select($db_id)) {
      return $redis[$server_id];
    }
  }

  return FALSE;
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
 * Get the full key for the cache item.
 *
 * @param string $key
 *   The key to set.
 * @param string $bin = 'cache'
 *   The cache table name
 *
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
  $full_key = (!empty($prefix) ? $prefix .':' : '') . $bin .':'. $key;

  return urlencode($full_key);
}

