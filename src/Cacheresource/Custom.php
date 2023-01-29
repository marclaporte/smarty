<?php

namespace Smarty\Cacheresource;

/**
 * Smarty Internal Plugin
 *


 */

use Smarty\Exception;
use Smarty\Smarty;
use Smarty\Template;
use Smarty\Template\Cached;

/**
 * Cache Handler API
 *


 * @author     Rodney Rehm
 */
abstract class Custom extends Base
{
    /**
     * fetch cached content and its modification time from data source
     *
     * @param string  $id         unique cache content identifier
     * @param string  $name       template name
     * @param string  $cache_id   cache id
     * @param string  $compile_id compile id
     * @param string  $content    cached content
     * @param integer $mtime      cache modification timestamp (epoch)
     *
     * @return void
     */
    abstract protected function fetch($id, $name, $cache_id, $compile_id, &$content, &$mtime);

    /**
     * Fetch cached content's modification timestamp from data source
     * {@internal implementing this method is optional.
     *  Only implement it if modification times can be accessed faster than loading the complete cached content.}}
     *
     * @param string $id         unique cache content identifier
     * @param string $name       template name
     * @param string $cache_id   cache id
     * @param string $compile_id compile id
     *
     * @return integer|boolean timestamp (epoch) the template was modified, or false if not found
     */
    protected function fetchTimestamp($id, $name, $cache_id, $compile_id)
    {
        return false;
    }

    /**
     * Save content to cache
     *
     * @param string       $id         unique cache content identifier
     * @param string       $name       template name
     * @param string       $cache_id   cache id
     * @param string       $compile_id compile id
     * @param integer|null $exp_time   seconds till expiration or null
     * @param string       $content    content to cache
     *
     * @return boolean      success
     */
    abstract protected function save($id, $name, $cache_id, $compile_id, $exp_time, $content);

    /**
     * Delete content from cache
     *
     * @param string|null  $name       template name
     * @param string|null  $cache_id   cache id
     * @param string|null  $compile_id compile id
     * @param integer|null $exp_time   seconds till expiration time in seconds or null
     *
     * @return integer      number of deleted caches
     */
    abstract protected function delete($name, $cache_id, $compile_id, $exp_time);

    /**
     * populate Cached Object with metadata from Resource
     *
     * @param \Smarty\Template\Cached   $cached    cached object
     * @param Template $_template template object
     *
     * @return void
     */
    public function populate(\Smarty\Template\Cached $cached, Template $_template)
    {
        $_cache_id = isset($cached->cache_id) ? preg_replace('![^\w\|]+!', '_', $cached->cache_id) : null;
        $_compile_id = isset($cached->compile_id) ? preg_replace('![^\w]+!', '_', $cached->compile_id) : null;
        $path = $cached->getSource()->uid . $_cache_id . $_compile_id;
        $cached->filepath = sha1($path);
        if ($_template->getSmarty()->cache_locking) {
            $cached->lock_id = sha1('lock.' . $path);
        }
        $this->populateTimestamp($cached);
    }

    /**
     * populate Cached Object with timestamp and exists from Resource
     *
     * @param \Smarty\Template\Cached $cached
     *
     * @return void
     */
    public function populateTimestamp(\Smarty\Template\Cached $cached)
    {
        $mtime =
            $this->fetchTimestamp($cached->filepath, $cached->getSource()->name, $cached->cache_id, $cached->compile_id);
        if ($mtime !== null) {
            $cached->timestamp = $mtime;
            $cached->exists = !!$cached->timestamp;
            return;
        }
        $timestamp = null;
        $this->fetch(
            $cached->filepath,
            $cached->getSource()->name,
            $cached->cache_id,
            $cached->compile_id,
            $cached->content,
            $timestamp
        );
        $cached->timestamp = $timestamp ?? false;
        $cached->exists = !!$cached->timestamp;
    }

    public function process(
	    Template               $_smarty_tpl,
	    \Smarty\Template\Cached $cached = null
    ) {
        if (!$cached) {
            $cached = $_smarty_tpl->getCached();
        }
        $content = $cached->content ? $cached->content : null;
        $timestamp = $cached->timestamp ? $cached->timestamp : null;
        if ($content === null || !$timestamp) {
            $this->fetch(
                $_smarty_tpl->getCached()->filepath,
                $_smarty_tpl->getSource()->name,
                $_smarty_tpl->cache_id,
                $_smarty_tpl->compile_id,
                $content,
                $timestamp
            );
        }
        if (isset($content)) {
            eval('?>' . $content);
            $cached->content = null;
            return true;
        }
        return false;
    }

    /**
     * Write the rendered template output to cache
     *
     * @param Template $_template template object
     * @param string                   $content   content to cache
     *
     * @return boolean                  success
     */
    public function storeCachedContent(Template $_template, $content)
    {
        return $this->save(
            $_template->getCached()->filepath,
            $_template->getSource()->name,
            $_template->cache_id,
            $_template->compile_id,
            $_template->cache_lifetime,
            $content
        );
    }

    /**
     * Read cached template from cache
     *
     * @param Template $_template template object
     *
     * @return string|boolean  content
     */
    public function retrieveCachedContent(Template $_template)
    {
        $content = $_template->getCached()->content ?: null;
	    if ($content === null) {
            $timestamp = null;
            $this->fetch(
                $_template->getCached()->filepath,
                $_template->getSource()->name,
                $_template->cache_id,
                $_template->compile_id,
                $content,
                $timestamp
            );
        }
        if (isset($content)) {
            return $content;
        }
        return false;
    }

	/**
	 * Empty cache
	 *
	 * @param \Smarty\Smarty $smarty Smarty object
	 * @param null $exp_time expiration time (number of seconds, not timestamp)
	 *
	 * @return integer number of cache files deleted
	 */
    public function clearAll(\Smarty\Smarty $smarty, $exp_time = null)
    {
        return $this->delete(null, null, null, $exp_time);
    }

    /**
     * Empty cache for a specific template
     *
     * @param \Smarty\Smarty  $smarty        Smarty object
     * @param string  $resource_name template name
     * @param string  $cache_id      cache id
     * @param string  $compile_id    compile id
     * @param integer $exp_time      expiration time (number of seconds, not timestamp)
     *
     * @return int number of cache files deleted
     * @throws \Smarty\Exception
     */
    public function clear(\Smarty\Smarty $smarty, $resource_name, $cache_id, $compile_id, $exp_time)
    {
        $cache_name = null;
        if (isset($resource_name)) {
            $source = \Smarty\Template\Source::load(null, $smarty, $resource_name);
            if ($source->exists) {
                $cache_name = $source->name;
            } else {
                return 0;
            }
        }
        return $this->delete($cache_name, $cache_id, $compile_id, $exp_time);
    }

	/**
	 * Check is cache is locked for this template
	 *
	 * @param Smarty $smarty Smarty object
	 * @param Cached $cached cached object
	 *
	 * @return boolean               true or false if cache is locked
	 */
    public function hasLock(\Smarty\Smarty $smarty, \Smarty\Template\Cached $cached)
    {
        $id = $cached->lock_id;
        $name = $cached->getSource()->name . '.lock';
        $mtime = $this->fetchTimestamp($id, $name, $cached->cache_id, $cached->compile_id);
        if ($mtime === null) {
            $this->fetch($id, $name, $cached->cache_id, $cached->compile_id, $content, $mtime);
        }
        return $mtime && ($t = time()) - $mtime < $smarty->locking_timeout;
    }

    /**
     * Lock cache for this template
     *
     * @param \Smarty\Smarty                 $smarty Smarty object
     * @param \Smarty\Template\Cached $cached cached object
     *
     * @return bool|void
     */
    public function acquireLock(\Smarty\Smarty $smarty, \Smarty\Template\Cached $cached)
    {
        $cached->is_locked = true;
        $id = $cached->lock_id;
        $name = $cached->getSource()->name . '.lock';
        $this->save($id, $name, $cached->cache_id, $cached->compile_id, $smarty->locking_timeout, '');
    }

    /**
     * Unlock cache for this template
     *
     * @param \Smarty\Smarty                 $smarty Smarty object
     * @param \Smarty\Template\Cached $cached cached object
     *
     * @return bool|void
     */
    public function releaseLock(\Smarty\Smarty $smarty, \Smarty\Template\Cached $cached)
    {
        $cached->is_locked = false;
        $name = $cached->getSource()->name . '.lock';
        $this->delete($name, $cached->cache_id, $cached->compile_id, null);
    }
}
