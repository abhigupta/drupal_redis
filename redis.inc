<?php
// $Id$


/**
 * @file
 * This file is alternative Drupal cache implementation. To configure
 * it properly please refer to README.txt.
 */
require dirname(__FILE__) .'/dredis.php';

// We default this to true
//$conf += array('page_cache_fastpath' => TRUE);

/**
 * Return data from the persistent cache. Data may be stored as either plain text or as serialized data.
 * cache_get will automatically return unserialized objects and arrays.
 *
 * @param $cid
 *   The cache ID of the data to retrieve.
 * @param $table
 *   The table $table to store the data in. Valid core values are 'cache_filter',
 *   'cache_menu', 'cache_page', or 'cache' for the default cache.
 */
function cache_get($cid, $table = 'cache') {
  global $user, $conf;

  // Garbage collection necessary when enforcing a minimum cache lifetime
  //  $cache_flush = variable_get('cache_flush_'. $table, 0);
  //  if ($cache_flush && ($cache_flush + variable_get('cache_lifetime', 0) <= time())) {
  //    // Reset the variable immediately to prevent a meltdown in heavy load situations.
  //    variable_set('cache_flush_'. $table, 0);
  //    // Time to flush old cache data.
  //    dredis_flush($table);
  //  }

  $cache_object = unserialize(dredis_get($cid, $table));
  if (isset($cache_object->data)) {
    if ($user->cache > $cache_object->created) {
      return 0;
    }
    else {
      return $cache_object;
    }
    //    // If the data is permanent or we're not enforcing a minimum cache lifetime
    //    // always return the cached data.
    //    if ($cache_object->expire == CACHE_PERMANENT || !variable_get('cache_lifetime', 0)) {
    ////      if ($cache_object->serialized) {
    ////        $cache_object->data = unserialize($cache_object->data);
    ////      }
    //    }
    //    // If enforcing a minimum cache lifetime, validate that the data is
    //    // currently valid for this user before we return it by making sure the
    //    // cache entry was created before the timestamp in the current session's
    //    // cache timer. The cache variable is loaded into the $user object by
    //    // sess_read() in session.inc.
    //    else {
    //      if ($user->cache > $cache_object->created) {
    //        // This cache data is too old and thus not valid for us, ignore it.
    //        return 0;
    //      }
    //      else {
    //        if ($cache_object->serialized) {
    //          $cache_object->data = unserialize($cache_object->data);
    //        }
    //      }
    //    }
    //    return $cache_object;
  }
  return 0;
}

/**
 * Store data in the persistent cache.
 *
 * The persistent cache is split up into four database
 * tables. Contributed modules can add additional tables.
 *
 * 'cache_page': This table stores generated pages for anonymous
 * users. This is the only table affected by the page cache setting on
 * the administrator panel.
 *
 * 'cache_menu': Stores the cachable part of the users' menus.
 *
 * 'cache_filter': Stores filtered pieces of content. This table is
 * periodically cleared of stale entries by cron.
 *
 * 'cache': Generic cache storage table.
 *
 * The reasons for having several tables are as follows:
 *
 * - smaller tables allow for faster selects and inserts
 * - we try to put fast changing cache items and rather static
 *   ones into different tables. The effect is that only the fast
 *   changing tables will need a lot of writes to disk. The more
 *   static tables will also be better cachable with MySQL's query cache
 *
 * @param $cid
 *   The cache ID of the data to store.
 * @param $data
 *   The data to store in the cache. Complex data types will be automatically serialized before insertion.
 *   Strings will be stored as plain text and not serialized.
 * @param $table
 *   The table $table to store the data in. Valid core values are 'cache_filter',
 *   'cache_menu', 'cache_page', or 'cache'.
 * @param $expire
 *   One of the following values:
 *   - CACHE_PERMANENT: Indicates that the item should never be removed unless
 *     explicitly told to using cache_clear_all() with a cache ID.
 *   - CACHE_TEMPORARY: Indicates that the item should be removed at the next
 *     general cache wipe.
 *   - A Unix timestamp: Indicates that the item should be kept at least until
 *     the given time, after which it behaves like CACHE_TEMPORARY.
 * @param $headers
 *   A string containing HTTP header information for cached pages.
 */
function cache_set($cid, $data, $table = 'cache', $expire = CACHE_PERMANENT, $headers = NULL) {
  //  return $cache->set($cid, $value, $expire, $headers, $table);

  $created = time();

  // Create new cache object.
  $cache          = new stdClass;
  $cache->cid     = $cid;
  $cache->created = $created;
  $cache->data    = $data;
  $cache->headers = $headers;

  if ($expire == CACHE_TEMPORARY) {
    // Convert CACHE_TEMPORARY (-1) into something that will live in redis
    // until the next flush.

    $cache->expire = $created + 2591999;
  }
  elseif ($expire == CACHE_PERMANENT) {
    $cache->expire = 0;
  }
  else {
    $cache->expire = $expire;
    $expire = ($expire - $created);
  }
  // We manually track the expire time in $cache->expire.  When the object
  // expires, we only allow one request to rebuild it to avoid cache stampedes.
  // Other requests for the expired object while it is still being rebuilt get
  // the expired object.
  dredis_set($cid, serialize($cache), $expire, $table);
}

/**
 *
 * Expire data from the cache. If called without arguments, expirable
 * entries will be cleared from the cache_page and cache_block tables.
 *
 * @param $cid
 *   If set, the cache ID to delete. Otherwise, all cache entries that can
 *   expire are deleted.
 *
 * @param $table
 *   If set, the table $table to delete from. Mandatory
 *   argument if $cid is set.
 *
 * @param $wildcard
 *   If set to TRUE, the $cid is treated as a substring
 *   to match rather than a complete ID. The match is a right hand
 *   match. If '*' is given as $cid, the table $table will be emptied.
 */
function cache_clear_all($cid = NULL, $table = NULL, $wildcard = FALSE) {
  global $cache;
  global $user;

  if (!isset($cid) && !isset($table)) {
    // Clear the block cache first, so stale data will
    // not end up in the page cache.
    dredis_flush('cache_block');
    dredis_flush('cache_page');
    return;
  }

  if (empty($cid)) {
    if (variable_get('cache_lifetime', 0)) {
      // We store the time in the current user's $user->cache variable which
      // will be saved into the sessions table by sess_write(). We then
      // simulate that the cache was flushed for this user by not returning
      // cached data that was cached before the timestamp.
      $user->cache = time();

      $cache_flush = variable_get('cache_flush_'. $table, 0);
      if ($cache_flush == 0) {
        // This is the first request to clear the cache, start a timer.
        variable_set('cache_flush_'. $table, time());
      }
      else if (time() > ($cache_flush + variable_get('cache_lifetime', 0))) {
        // Clear the cache for everyone, cache_flush_delay seconds have
        // passed since the first request to clear the cache.
        dredis_flush($table);
        variable_set('cache_flush_'. $table, 0);
      }
    }
    else {
      // No minimum cache lifetime, flush all temporary cache entries now.
      dredis_flush($table);
    }
  }
  else {
    dredis_delete($cid, $wildcard, $table);
  }
}

/**
 * Main callback from DRUPAL_BOOTSTRAP_EARLY_PAGE_CACHE phase.
 *
 * It is enabled by default. To disable you should
 * set $conf['page_cache_fastpath'] to FALSE in your web-site's
 * settings.php file.
 */
//function _cache_page_cache_fastpath() {
//  global $base_root;
//  global $cache;
//  global $conf;
//
//  if (empty($_POST) && !isset($_COOKIE['cache'])) {
//    if (!isset($cache)) {
//      $cache = new DrupalCache();
//    }
//
//    // In case $conf['cache'] is hardcoded in settings.php.
//    if (isset($conf['cache']) && $conf['cache'] == CACHE_DISABLED) {
//      return FALSE;
//    }
//
//    if ($cache->page_fast_cache('cache_page')) {
//      $page = $cache->get($base_root . request_uri(), 'cache_page');
//      if (!empty($page)) {
//        $set = FALSE;
//        // You might want to hardcode $conf['page_compression'] in settings.php
//        // to speed up things a little.
//        if (!isset($conf['page_compression'])) {
//          $conf['page_compression'] = substr($page->data, 0, 3) == "\x1f\x8b\x08";
//          $set = TRUE;
//        }
//        drupal_page_cache_header($page);
//        if ($set) {
//          unset($conf['page_compression']);
//        }
//        return TRUE;
//      }
//    }
//  }
//  return FALSE;
//}

/**
 * Check is some other module implements page_cache_fastpath() e.g. authcache.
 */
if (!function_exists('page_cache_fastpath')) {
  function page_cache_fastpath() {
    return FALSE;
    return _cache_page_cache_fastpath();
  }
}

