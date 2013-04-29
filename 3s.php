<?php
/**
 * Copyright 2011-2012 Denis Sokolov and Slik
 * The program is distributed under the terms of the GNU Lesser General Public License.
 *
 * Project page: http://akral.bitbucket.org/3s/
 * Authors: http://sokolov.cc/, http://slik.eu/
 *
 * This file is part of 3s.
 *
 * 3s is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * 3s is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with 3s.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * 3s handles requests to CSS, Javascript and CSS sprites.
 * 3s does not care at what level in a subfolder it exists.
 *
 * You can include this file, instantiate ThreeS and use helper functions:
 *	$ts = new ThreeS();
 *	$ts->css('main');
 *	$ts->js('uploader');
 * There is also an alternative syntax for purists:
 *	$ts->path('css', 'main');
 *	$ts->path('js', 'uploader');
 * Returns path to put into your <link> and <script> tags.
 * You should care about the base (3s folder),
 *	3s only returns you the part internal to 3s.
 */

if (strtolower(realpath(__FILE__)) == strtolower(realpath($_SERVER['SCRIPT_FILENAME']))) { // We are running
	require 'handler.php';
}


class ThreeS
{
	public $tmp;

	const CODE_LENGTH = 5;
	const DIR_LEVELS = 3;

	public function __construct($config = null)
	{
		if (is_null($config))
			require dirname(__FILE__).'/config.php';
		$this->tmp = __DIR__ . '/tmp';
		$this->config = $config;
		$this->debug = $this->config('debug', false);
		$this->setupCache();
	}

	public function handle($path = null)
	{
		$this->path = is_null($path) ? $_SERVER['QUERY_STRING'] : $path;

		$request = $this->parse($this->path);

		$handler = $this->handler($request);
		if (!$handler->exists())
			throw new ThreeS404Exception('Handler claims it does not exist');

		if ($request->code != $handler->code()) {
			if ($this->config('codes/strict'))
				throw new ThreeS404Exception('Code is incorrect');
			return $this->redirect($handler->url(), 301);
		}

		if ($handler->mtime() > $request->mtime)
			return $this->redirect($handler->url(), 301);
		if ($handler->mtime() < $request->mtime)
			return $this->redirect($handler->url(), 307);

		# Backwards compatibility
		if ($request->url != $handler->url())
			return $this->redirect($handler->url(), 301);

		$cache = $this->cache($handler);
		$gzip = false;
		if ($this->gzip() && $handler->gzip) {
			$cache .= '.gz';
			$gzip = true;
		}

		if (file_exists($cache)
				&& filemtime($cache) >= $request->mtime
				&& !$this->refresh())
			$data = file_get_contents($cache);
		else {
			$data = $handler->data();
			$gzipData = function_exists('gzencode') ? gzencode($data, 9) : null;
			$this->cache($handler, $data, $gzipData);

			if ($gzip && $gzipData)
				$data = $gzipData;
		}

		header('Cache-Control: max-age=' . 364*24*3600);
		header('Content-Length: ' . strlen($data));
		header('Content-Type: ' . $handler->mime);
		header('Expires: ' . gmdate('D, d M Y H:i:s T', time() + 364*24*3600));
		header('Vary: Accept-Encoding');

		if ($gzip)
			header('Content-Encoding: gzip');
		echo $data;
	}

	public function css($keyword, array $options = array())
	{
		return $this->path('css', $keyword, $options);
	}

	public function invalidate()
	{
		# Replace table with an empty array
		$this->cacheTable(array());
	}

	public function js($keyword, array $options = array())
	{
		return $this->path('js', $keyword, $options);
	}

	public function path($module, $keyword, array $options = array())
	{
		if (is_array($keyword))
			$keyword = implode(',', $keyword);

		// Check for URL in the cache
		if (!$this->config('cache/autoinvalidate')) {
			$path = $this->cacheTable($module, $keyword, $options);
			if ($path) {
				return $path;
			}
		}

		$handler = $this->handler((object) array(
			'keyword' => $keyword,
			'options' => $options,
			'mtime' => 0,
			'ext' => $module,
		));
		if (!$handler->exists())
			throw new ThreeS404Exception(
				'Handler claims there are no useful files for this keyword');

		// Save url in the cache
		if (!$this->config('cache/autoinvalidate'))
			$this->cacheTable(
				$module, $keyword, $options,
				$handler->url()
			);

		return $handler->url();
	}

	public function handler(stdClass $request)
	{
		if (!property_exists($request, 'keyword'))
			throw new Exception('missing property');
		if (is_array($request->keyword))
			$request->keyword = implode(',', $request->keyword);

		$params = array(
			'bundle' => $this->config($request->ext.'/bundles/'.$request->keyword),
			'keyword' => $request->keyword,
			'ext' => $request->ext,
			'entropy' => $this->config('codes/entropy', ''),
			'hooks' => $this->hooks($this->config($request->ext.'/hooks', array())),
			'minify' => $this->config($request->ext.'/minify', 2),
			'options' => $request->options,
			'pretty' => $this->config($request->ext.'/pretty', false),
			'ts' => $this,
		);
		if (strpos($request->keyword, ',') !== false)
			return new ThreeSMultipleHandler($params);

		switch ($request->ext) {
			case 'png': return new ThreeSSpriteHandler($params);
			case 'css':
				$params['lessc'] = $this->config('css/lessc');
				return new ThreeSCssHandler($params);
			case 'js': return new ThreeSJsHandler($params);
			default:
				throw new ThreeSException('Unknown request extension.');
		}
	}

	public static function writable($path)
	{
		if (file_exists($path))
			return is_writable($path);

		return is_writable(dirname($path));
	}

	protected function cache($handler, $data = null, $gzipped = null)
	{
		$url = $handler->url();
		$file = $this->tmp . '/' . $url;
		if (is_null($data))
			return $file;

		$dir = dirname($file);
		if (!file_exists($dir) && self::writable($dir))
			mkdir($dir, 0777);

		// Delete old versions
		$this->cacheCleanup(str_replace($handler->mtime(), '*', $url));

		if (self::writable($file))
			file_put_contents($file, $data);
		if ($handler->gzip && self::writable($file .'.gz') && $gzipped)
			file_put_contents($file .'.gz', $gzipped);

		return $this;
	}

	protected function cacheCleanup($pattern)
	{
		$pattern = $this->tmp . '/' . $pattern;
		$files = glob($pattern) ?: array();
		foreach ($files as $file)
			if (is_writable($file))
				unlink($file);
		$pattern .= '.gz';
		$files = glob($pattern) ?: array();
		foreach ($files as $file)
			if (is_writable($file))
				unlink($file);
		return $this;
	}

	protected function cacheTable($module, $keyword = null, $options = array(), $data='')
	{
		$path = $this->tmp .'/'. 'table.json';

		// First use case, calling with only one argument to clean the table
		if (is_null($keyword))
			return $this->cacheTableWrite($path, array());

		$table = $this->cacheTableRead($path);

		// A fingerprint
		$hash = md5($module . $keyword . json_encode($options));

		// Second use case, retrieve an entry
		if (!$data)
			return empty($table[$hash]) ? false : $table[$hash];

		// Third use case, add a new entry
		if (isset($table[$hash])) {
			if ($data === $table[$hash]) {
				// Race condition, everything is fine
				return;
			}
			throw new ThreeSException('Did we just hit another MD5 collision?');
		}
		$table[$hash] = $data;
		$this->cacheTableWrite($path, $table);
	}

	protected function cacheTableRead($path)
	{
		if (!file_exists($path) || !is_readable($path) || !is_file($path))
			return array();
		return json_decode(file_get_contents($path), true) ?: array();
	}

	protected function cacheTableWrite($path, array $data)
	{
		if (self::writable($path))
			file_put_contents($path, json_encode($data));
	}

	protected function config($keys, $default = null)
	{
		if (is_string($keys))
			$keys = explode('/', $keys);

		$cfg = $this->config;
		foreach ($keys as $k) {
			if (!isset($cfg[$k]))
				return $default;
			$cfg = $cfg[$k];
		}

		return $cfg;
	}

	protected function gzip()
	{
		if ($this->debug)
			return false;
		return !empty($_SERVER['HTTP_ACCEPT_ENCODING'])
			&& strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false;
	}

	protected function hooks($hooks)
	{
		$result = array();
		$pool = array(); # stores created objects to allow them to store state
		foreach ($hooks as $k=>$list) {
			if (isset($list['file']))
				$list = array($list);
			$result[$k] = array();
			foreach ($list as $h) {
				$key = $h['file'].'-'.$h['class'];
				if (!isset($pool[$key])) {
					require_once 'hooks/'.$h['file'];
					$cl = new $h['class'];
					$pool[$key] = $cl;
				}
				$result[$k][] = array($pool[$key], $h['method']);
			}
		}
		return $result;
	}

	protected function parse($path)
	{
		if (!preg_match(
				'@^'
				.'(?P<keyword>[a-zA-Z0-9_,-]+)'
				.'(?:\.(?P<options>(?:[a-z]+-[a-zA-Z0-9>-_]+,?)+))?'
				.'(?:\.(?P<mtime>\d+))?'
				.'(?:[./](?P<code>[a-zA-Z0-9_-]{'.self::CODE_LENGTH.'}))?'
				.'\.(?P<ext>css|js|png)$@',
				$path, $match))
			throw new ThreeS404Exception('URL does not adhere to our structure');

		$match['mtime'] = (int) $match['mtime'];
		if (empty($match['mtime']))
			$match['mtime'] = 0;

		if (empty($match['code']))
			$match['code'] = '';

		// foo-bar,key-value
		$options = array();
		if (empty($match['options']))
			$match['options'] = array();
		else {
			foreach (explode(',', $match['options']) as $block) {
				$t = explode('-', $block, 2);
				if (count($t) !== 2)
					throw new ThreeS404Exception('Option key-value pairs are misconstructed');
				$options[$t[0]] = $t[1];
			}
		}
		$match['options'] = $options;

		$match['url'] = $match[0];

		return (object) $match;
	}

	protected function redirect($url, $status = 302)
	{
		$t = explode($this->path, $_SERVER['REQUEST_URI']);
		$base = $t[0];
		$url = $base . $url;
		header('Location: ' . $url, true, $status);
		return $this;
	}

	protected function refresh()
	{
		return strpos($_SERVER['HTTP_CACHE_CONTROL'], 'no-cache') !== false;
	}

	protected function setupCache()
	{
		$folder = $this->tmp;
		if (!file_exists($folder) && !@mkdir($folder, 0777, true))
			return false;
		if (!is_writable($folder) && !@chmod($folder, 0777))
			return false;
		return true;
	}
}

class ThreeSBaseHandler
{
	public $src = '', $ext = '', $mime = '', $gzip = true,
		$combinable = true, $hooks, $entropy, $keyword, $minify = 2;

	protected $mtime, $options, $bundle, $files, $pausedHooks = array();

	/**
	 * @param string keyword
	 * @param string ext
	 * @param string entropy
	 * @param array hooks
	 * @param string options
	 * @param bool pretty
	 */
	public function __construct(array $params)
	{
		$this->keyword = $params['keyword'];

		foreach (explode(',', 'bundle,hooks,ext,minify,pretty,entropy,ts') as $k)
			if (isset($params[$k]))
				$this->$k = $params[$k];

		if (!$this->src)
			$this->src = $this->ext;

		# Init options for hooked commands
		$this->options($params['options']);
	}

	public function code()
	{
		// md5 is quick, there is no real security here
		// base64 to pack more bits in the same number of characters
		// strtr because + and / suck in URLs
		// substr because we want the code to be short
		$encoded = base64_encode(md5($this->keyword . __FILE__ . $this->entropy));
		return substr(strtr($encoded, '+/', '-_'), 0, ThreeS::CODE_LENGTH);
	}

	public function data()
	{
		$result = $this->hook('pre', '');
		foreach ($this->files() as $file)
			$result .= $this->readFile($file) . "\n";
		$result = $this->hook('post', $result);
		return $result;
	}

	public function exists()
	{
		return $this->hook('exists', (bool) $this->files());
	}

	public function files()
	{
		# Cached
		if ($this->files)
			return $this->files;

		if ($this->bundle)
			$list = $this->files($this->bundle);
		else {
			$list = $this->hook('files', array());
			if ($list === array())
				$list = $this->filesDetect(dirname(__FILE__));
		}

		$list = $this->hook('files-pre-recurse', (array) $list);

		if ($list)
			$list = $this->filesRecurse($list);


		$list = $this->hook('files-post', (array) $list);

		$this->files = $list;
		return $this->files;
	}

	public function mtime()
	{
		if (empty($this->mtime)) {
			$max = 0;
			foreach ($this->files() as $path)
				$max = max($max, filemtime($path));
			$max = $this->hook('mtime', $max);
			$this->mtime = $max;
		}
		return $this->mtime;
	}

	public function option($name)
	{
		$options = $this->options();
		if (!isset($options[$name]))
			return '';
		return $options[$name];
	}

	public function url()
	{
		// Stringify options
		$options = $this->options();
		if (empty($options))
			$options = '';
		else {
			$t = array();
			foreach ($options as $k=>$v)
				if ($v)
					$t[] = $k . '-' . $v;
			$options = implode(',', $t);
		}

		return $this->keyword .'.'
			. ($options ? $options.'.' : '')
			. $this->mtime() .'/'. $this->code() .'.'. $this->ext;
	}

	protected function hook($name, $data, $default = null)
	{
		if (isset($this->hooks[$name]) && !$this->hookPaused($name))
			try {
				foreach ($this->hooks[$name] as $h)
					$data = call_user_func($h, $this, $data);
			} catch (Exception $e) {
				if ($e->getCode() === 404)
					throw new ThreeS404Exception($e->getMessage());
				throw $e;
			} elseif (func_num_args() > 2)
			$data = $default;
		return $data;
	}

	protected function hookPaused($name)
	{
		return isset($this->pausedHooks[$name]) && $this->pausedHooks[$name];
	}

	protected function options($options = null)
	{
		if (!is_null($options))
			$this->options = $this->hook('options', $options);
		return $this->options;
	}

	protected function pauseHook($name)
	{
		$this->pausedHooks[$name] = true;
	}

	protected function readFile($filename)
	{
		return file_get_contents($filename);
	}

	protected function unpauseHook($name)
	{
		$this->pausedHooks[$name] = false;
	}

	/**
	 * Following functions would make more sense as a separate class.
	 */

	protected function filesDetect($origin)
	{
		$path = $this->filesDetectFolder($origin);
		if ($path)
			return (array) $path;

		$path = $this->filesDetectSingleFile($origin);
		if ($path)
			return (array) $path;

		return null;
	}

	protected function filesDetectFolder($origin)
	{
		for ($levels = 0; $levels < ThreeS::DIR_LEVELS; $levels++) {
			$path = $origin .'/'. $this->ext .'/'. $this->keyword;
			if (file_exists($path))
				return $path;
			$origin = dirname($origin);
		}
		return null;
	}

	protected function filesDetectSingleFile($origin)
	{
		for ($levels = 0; $levels < ThreeS::DIR_LEVELS; $levels++) {
			$path = $origin .'/'. $this->ext .'/'. $this->keyword . '.' . $this->ext;
			if (file_exists($path))
				return $path;
			$origin = dirname($origin);
		}
		return null;
	}

	protected function filesRecurse($list)
	{
		# Have a list of paths, let's work through it
		$result = array();
		foreach ($list as $path) {
			if (is_dir($path))
				$result = array_merge(
					$result,
					$this->filesRecurse($this->filesDirectoryList($path))
				);

			if (is_file($path))
				$result[] = $path;
		}
		return $result;
	}

	protected function filesDirectoryList($dir)
	{
		$result = array();
		foreach (scandir($dir) as $item) {
			# Ignore hidden files
			if (substr($item, 0, 1) == '.')
				continue;

			$path = $dir .'/' . $item;
			if (is_dir($path))
				$result[] = $path;
			else if (is_file($path) && preg_match('/^.+\.'.$this->src.'$/', $item))
				$result[] = $path;
		}
		return $result;
	}
}

class ThreeSMultipleHandler extends ThreeSBaseHandler
{
	public function __construct($params)
	{
		parent::__construct($params);
		$this->handlers = array();

		$keywords = explode(',', $params['keyword']);
		if (!count($keywords))
			throw new ThreeS404Exception('No keywords are passsed');

		if (count(array_unique($keywords)) != count($keywords))
			throw new ThreeS404Exception('Found duplicate keywords');

		foreach ($keywords as $keyword) {
			$h = $this->ts->handler((object) array(
				'keyword' => $keyword,
				'options' => $this->options,
				'mtime' => 0,
				'ext' => $this->ext,
			));
			if (!$h->combinable)
				throw new ThreeS404Exception(
					'Unable to combine different keywords of this type');
			unset($h->hooks['mtime']);
			unset($h->hooks['pre']);
			unset($h->hooks['post']);
			$this->handlers[] = $h;
		}

		$this->mime = $this->handlers[0]->mime;
	}

	public function data()
	{
		$result = '';
		$result = $this->hook('pre', $result);
		foreach ($this->handlers as $h)
			$result .= $h->data()."\n";
		$result = $this->hook('post', $result);
		return $result;
	}

	public function files()
	{
		$result = array();
		foreach ($this->handlers as $h)
			$result = array_merge($result, (array) $h->files());
		return $result;
	}

	public function mtime()
	{
		if (empty($this->mtime)) {
			$result = 0;
			foreach ($this->handlers as $h)
				$result = max($result, $h->mtime());
			$this->mtime = $this->hook('mtime', $result);
		}
		return $this->mtime;
	}
}

class ThreeSSpriteHandler extends ThreeSBaseHandler
{
	public $src = '(png|jpg|jpeg|gif)', $ext = 'png',
		$mime = 'image/png', $gzip = false, $combinable = false;

	public $padding = 30;

	public function data()
	{
		$details = $this->details();
		$sprite = imagecreatetruecolor($details->w, $details->h);
		imagealphablending($sprite, false);
		imagesavealpha($sprite, true);
		imagefill($sprite, 0, 0, imagecolorallocatealpha($sprite, 225, 225, 225, 127));
		foreach ($details->icons as $icon) {
			imagecopy($sprite,
				imagecreatefromstring(file_get_contents($icon->path)),
				0, $icon->offset,
				0, 0,
				$icon->w, $icon->h
			);
		}
		ob_start();
		imagepng($sprite, null, 9, PNG_ALL_FILTERS);
		return ob_get_clean();
	}

	public function details()
	{
		if (empty($this->details)) {
			$result = array(
				'w' => 0,
				'h' => 0,
				'icons' => array(),
				'lastPadding' => null,
			);
			foreach ($this->files() as $path) {
				list($w, $h, $type, $attr) = getimagesize($path);
				$padding = $this->padding($path);

				// If current image requires a bigger padding than is already
				// and the end of file.s
				if (!is_null($result['lastPadding'])
					&& $result['lastPadding'] < $padding)
				{
					$result['h'] += $padding - $result['lastPadding'];
				}

				$result['icons'][basename($path)] = (object) array(
					'w' => $w,
					'h' => $h,
					'path' => $path,
					'offset' => $result['h'],
				);
				$result['w'] = max($result['w'], $w);
				$result['h'] += $h + $padding;
				$result['lastPadding'] = $padding;
			}
			// Remove last padding
			// Uses $path from foreach!
			$result['h'] -= $this->padding($path);

			$this->details = (object) $result;
		}
		return $this->details;
	}

	public function options($options = null)
	{
		if (is_null($options))
			return parent::options();
		if (empty($options['direction']) || $options['direction'] !== 'v')
			throw new ThreeS404Exception('Only vertical sprites are supported now');
		$this->direction = $options['direction'];
		return parent::options($options);
	}

	protected function filesDetectFolder($origin)
	{
		# We want to find CSS folders and then go in a subfolder
		$this->ext = 'css';
		$folder = parent::filesDetectFolder($origin);
		$this->ext = 'png';
		return $folder . '/sprite-'.$this->direction;
	}

	protected function padding($path)
	{
		if (preg_match('/(\d+)\.'.$this->src.'$/', $path, $m))
			return (int) $m[1];
		return $this->padding;
	}
}

class ThreeSCssHandler extends ThreeSBaseHandler
{
	public $lessc, $src = 'css|less', $ext = 'css', $mime = 'text/css', $pretty;

	public function __construct($params)
	{
		parent::__construct($params);
		if (isset($params['lessc']))
			$this->lessc = $params['lessc'];
	}

	public function data()
	{
		$data = '';
		$raw_data = '';
		$data = $this->hook('pre', $data);
		foreach ($this->files() as $file) {
			if (substr($file, -8) === '.min.css') {
				$raw_data .= $this->readFile($file);
			} else {
				if ($this->pretty) {
					$data .= 'system_3s_message{'
						.'file-begin:"'
							.implode('/', array_slice(explode('/',$file), -3))
						.'";}'."\n";
				}
				$data .= $this->readFile($file);
			}
		}

		$data = $this->dataLESS($data);
		$data = $this->dataURLs($data);
		$data = $this->sprites($data);
		$data = $this->dataCSS3($data);

		$raw_data = $this->dataURLs($raw_data);
		$data = $raw_data . $data;

		if ($this->pretty) {
			$data = $this->dataPrettify($data);
		} elseif ($this->minify) {
			$data = $this->dataMinify($data);
		}
		$data = $this->hook('post', $data);
		return $data;
	}

		private function dataCSS3($data)
		{
			$data = $this->hook('pre-css3', $data);
			// CSS3 does not like comments
			$data = preg_replace('@/\*.*?\*/@s', '', $data);
			require_once dirname(__FILE__).'/inc/css3.php';
			try {
				$css3 = new CSS3();
				$data = $css3->parse($data);
			} catch (CSS3Exception $e) {
				throw new ThreeSException($e->getMessage(), $e->getCode(), $e);
			}
			return $data;
		}

		private function dataLESS($data)
		{
			$data = $this->hook('pre-less', $data);
			if ($this->lessc) {
				// Run lessc on a file
				// @throws ThreeSException if failed
				$lessc = $this->lessc;
				$run = function ($file) use ($lessc) {
					exec($lessc . ' --no-color '.escapeshellarg($file).' 2>&1', $out, $code);
					$out = implode("\n", $out);
					if ($code > 0) {
						throw new ThreeSException($out, $code);
					}
					return $out;
				};

				$tmp = tempnam('tmp', 'lessc');
				file_put_contents($tmp, $data);
				try {
					$data = $run($tmp);
				} catch (ThreeSException $e) {
					$msg = $e->getMessage();
					foreach (array(
						'/ParseError: Syntax Error on line (?P<line>\d+)/',
						'/in \/[^ ]+:(?P<line>\d+):/',
						) as $rule) {

						if (preg_match($rule, $msg, $m)) {
							return $this->convertLESSExceptionLocated($m['line']);
						}
					}
					$this->convertLESSExceptionIterated($run);
				}
			} else {
				require_once dirname(__FILE__).'/inc/lessc.inc.php';
				$less = new lessc();
				try {
					$data = $less->parse($data);
				} catch (Exception $e) {
					if (preg_match(
							'/parse error: failed at `(?P<at>[^`]+)` line: (?P<line>\d+)/',
							$e->getMessage(), $m)) {
						$line = ((int) $m['line']) - 1;
						$location = $m['at'];
						$this->convertLESSExceptionLocated($line, $location);
					} else {
						// If possible, this convert will throw
						// a more precise exception
						$this->convertLESSExceptionIterated(function ($file) use ($less) {
							return $less->parse(file_get_contents($file));
						});
						throw $e;
					}
				}
			}
			return $data;
		}

		private function convertLESSExceptionLocated($line, $location = '')
		{
			$file = null;
			$lineReal = null;
			$context = null;

			# Detect the offending line number in a file
			if ($this->pretty) {
				$this->pretty = false;
				$this->data(); # Go again. :)
			} else {
				$linesSeen = 0;
				foreach ($this->files() as $file) {
					$contents = $this->readFile($file);
					$fileLen = substr_count($contents, "\n") + 1;
					$linesSeen += $fileLen;
					if ($linesSeen > $line) {
						$linesSeen -= $fileLen;
						$lineReal = $line - $linesSeen;
						$context = $contents;
						break;
					}
				}
				$file = implode('/', array_slice(explode('/', $file), -3));
			}

			throw new ThreeSSyntaxException(array(
				'context' => $context,
				'file' => $file,
				'line' => $lineReal + 1, # Humans are 1-based
				'location' => $location,
			));
		}

		private function convertLESSExceptionIterated($callback)
		{
			require_once dirname(__FILE__).'/inc/lessc.inc.php';
			$less = new lessc();

			foreach ($this->files() as $file) {
				try {
					$callback($file);
				} catch (Exception $e) {
					$map = array(
						'ParseError: missing closing `}`' =>
							'Missing closing bracket',
						'parse error: unclosed block' =>
							'Missing closing bracket',
						'Invalid argument supplied for foreach\(\)' =>
							'Mismatching closing brackets',
					);

					$msg = $e->getMessage();
					foreach ($map as $rule => $text) {
						if (preg_match('/'.$rule.'/', $msg)) {
							$msg = $text;
						}
					}

					throw new ThreeSSyntaxException(array(
						'file' => $file,
						'message' => $msg,
					));
				}
			}

			// Did not find an exception, pass through
		}

		private function dataMinify($data)
		{
			$data = $this->hook('pre-minify', $data);

			// Squash whitespace
			$data = preg_replace('/\s+/', ' ', $data);
			// Remove whitespace around characters {};
			$data = preg_replace('/;? ?([{};]) ?/', '\\1', $data);

			return $data;
		}

		private function dataPrettify($data)
		{
			$data = $this->hook('pre-prettify', $data);

			// Semicolons (properties)
			$data = preg_replace('/;\s*/', ";\n\t", $data);

			// Opening braces (selectors)
			$data = preg_replace('/{\s*/', "{\n\t", $data);

			// Closing braces
			$data = preg_replace("/\s*}\s*/", "\n}\n\n", $data);

			// Space between the property name and value
			// (foo:bar -> foo: bar)
			$data = preg_replace('/([;{]\s*[a-z-]+:)/', '$1 ', $data);

			// System comment about the source
			$data = preg_replace('/system_3s_message\s*{\s*file-begin:\s*"([^"]+)";\s*}\s*/',
				"\n\n/*\n * The following is from file $1. \n * ".str_repeat('=', 60)."\n */\n\n", $data);

			return $data;
		}

		private function dataURLs($data)
		{
			# Add ../../ to any url, as we will pretend our CSS lies in /
			# It's important to keep this in mind when authoring CSS
			return preg_replace('/url\s*\(\s*([\'"]?)/', 'url(\1../../', $data);
		}

	protected function sprites($data)
	{
		$data = $this->hook('pre-sprites', $data);
		$this->sprites = $this->ts->handler((object) array(
			'keyword' => $this->keyword,
			'options' => array_merge($this->options, array('direction' => 'v')),
			'mtime' => 0,
			'ext' => 'png',
		));
		$data = preg_replace_callback(
			'/
				background\s*:	# background :
				([^;}]*)		# transparent
				url\(\s*		# url(
					[\'"]?		# quote
					\.\.\/\.\.\/sprite-v\/	# keyword
					([^\'")]+)	# icon name
					[\'"]?		# closing quote
				\s*\)\s*		# )
				(\s*(?:scroll|fixed|inherit|(?:no-)?repeat(?:-[xy])?)\s*){0,2}	# no-repeat fixed
				(0|-?\d+px|-?\d+%|left|right|center)? # h-pos
				\s*
				(0|-?\d+px|-?\d+%|top|bottom|center)? # y-pos
				[^;}]*		# something else? ignore
				([;}])			# end block
				/x',
			array($this, 'spritesNewCSSRule'),
			$data
		);
		if (preg_match('/background-image\s*:([^;}]*)url\(\s*[\'"]\.\.\/\.\.\/sprite-/', $data))
			throw new ThreeSException('background-image property is not supported with sprites yet.');
		if (preg_match('/background\s*:([^;}]*)url\(\s*[\'"]\.\.\/\.\.\/sprite-h/', $data))
			throw new ThreeSException('Horizontal sprites are not supported yet.');
		return $data;
	}

	protected function spritesNewCSSRule($match)
	{
		if (substr($match[5], -1) == '%')
			throw new ThreeSSanityException('Unable to sprite images with vertical position in percents!');

		$icon = $this->sprites->details()->icons[$match[2]];
		$offset = -$icon->offset;
		if (substr($match[5], -2) == 'px') {
			if (substr($match[5], 0, 1) == '-')
				$offset -= (int) substr($match[5], 1, -2);
			else
				$offset += (int) substr($match[5], 0, -2);
		}

		# Make no-repeat a new default
		if (strpos($match[3], 'repeat') === false)
			$match[3] = 'no-repeat '.$match[3];

		return 'background:'
			. $match[1] # Pre
			.' url(\'../'.$this->sprites->url().'\')'
			.' '.$match[3] # no-repeat, fixed
			.' '.($match[4] === '' ? 'center' : $match[4]) # bg-pos-x, center default
			.' '.$offset.'px' # bg-pos-y
			.' '.$match[6]; # post
	}
}

class ThreeSJsHandler extends ThreeSBaseHandler
{
	public $ext = 'js', $mime = 'application/javascript', $pretty;

	public function data()
	{
		if ($this->pretty) {
			require_once dirname(__FILE__).'/inc/jsbeautifier.php';

			$data = '';
			$data = $this->hook('pre', $data);
			foreach ($this->files() as $file) {
				$data .= "\n\n/* \n * The following is from file\n"
					.' * ' .implode('/', array_slice(explode('/',$file), -3)). "\n"
					." */\n\n";

				$cont = file_get_contents($file) .';'; # Stop JS from breaking.

				if (substr($file, -7) == '.min.js')
					$cont = "/*\n * Not prettified, because it looks like a library."
						."\n * If you want it prettified, make sure the filename"
						."\n * does not end with .min.js.\n */\n\n"
						. $cont;
				else {
					$cont = $this->hook('pre-prettify', $cont);
					$cont = js_beautify($cont);
					$cont = $this->hook('post-prettify', $cont);
				}

				$data .= $cont . "\n";
			}
			$data = $this->hook('post', $data);
		} else {
			if ($this->minify > 1)
				require_once dirname(__FILE__).'/inc/jsmin.php';

			$data = '';
			$data = $this->hook('pre', $data);
			foreach ($this->files() as $file) {
				$f = file_get_contents($file);
				if ($this->minify && strpos($file, '.min.') === false) {
					$f = $this->hook('pre-minify', $f);
					$f = preg_replace_callback('@/\*(?P<c>[^!].*?)\*/@is', function ($m) {
						$c = $m['c'];
						if (strpos($c, 'license') === false) {
							return '';
						}
						return '/*!'.$c.'*/';
					}, $f);
					if ($this->minify > 1)
						$f = JSMin::minify($f);
					else {
						$f = preg_replace('@(^|\s)//.+$@m', '', $f);
						$f = preg_replace('/[\r\t ]{2,}/', ' ', $f);
						$f = preg_replace('/\s*\n\s*/', "\n", $f);
					}
					$f = $this->hook('post-minify', $f);

				}
				$data .= $f.";\n";
			}
			$data = $this->hook('post', $data);
		}

		return $data;
	}
}

class ThreeSException extends Exception
{
	public function __construct($msg = '', $code = 0, Exception $previous = null)
	{
		if (method_exists($this, 'getPrevious'))
			parent::__construct($msg, $code, $previous);
		else {
			parent::__construct($msg, $code);
			$this->previous = $previous;
		}
	}

	public function __call($name, $arguments)
	{
		if ($name == 'getPrevious')
			return $this->previous;
		return parent::$name($arguments);
	}
}
class ThreeS404Exception extends ThreeSException {}
class ThreeSSanityException extends ThreeSException {}
class ThreeSSyntaxException extends ThreeSException
{
	public function __construct($options)
	{
		parent::__construct('Parsing error');
		$this->file = $options['file'];
		$this->line = isset($options['line']) ? $options['line'] : null;
		$this->message = isset($options['message']) ? $options['message'] : 'Syntax error';
		$this->context = isset($options['context']) ? $options['context'] : '';
		$this->location = isset($options['location']) ? $options['location'] : null;
	}

	public function getContext()
	{
		return $this->context;
	}

	public function getLocation()
	{
		return $this->location;
	}
}
