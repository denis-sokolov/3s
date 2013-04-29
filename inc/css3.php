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

class CSS3
{
	public $prefixes = 'webkit|moz|o|ms';

	// @at-rules
	public $ats = array(
		'keyframes' => 'webkit,moz',
	);

	public $rules = array(
		'animation' => 'webkit,moz,ms,o',
		'animation-name' => 'webkit,moz,ms,o',
		'animation-delay' => 'webkit,moz,ms,o',
		'animation-direction' => 'webkit,moz,ms,o',
		'animation-duration' => 'webkit,moz,ms,o',
		'animation-fill-mode' => 'webkit,moz,ms,o',
		'animation-iteration-count' => 'webkit,moz,ms,o',
		'animation-timing-function' => 'webkit,moz,ms,o',
		'backface-visibility' => 'webkit', // 3d transforms related
		# 'background-clip' // Only FF3.6 and 10.1 need prefixes, they also have alternative syntax
		'border-image' => 'webkit,moz,o',
		'border-radius' => 'webkit,moz', // Only FF3.6 and old iOS and Android
		'box-shadow' => 'webkit,moz',
		'box-sizing' => 'webkit,moz',
		'column-count' => 'webkit,moz',
		'column-gap' => 'webkit,moz',
		'object-fit' => 'o',
		'object-position' => 'o',
		'opacity' => '',
		'outline' => '',
		'perspective' => 'webkit,moz,o,ms', // 3d transforms related
		'resize' => '',
		'text-overflow' => 'o',
		'text-shadow' => '',
		'transform' => 'webkit,moz,o,ms',
		'transform-origin' => 'webkit,moz,o,ms',
		'transform-style' => 'webkit', // 3d transforms related
		'transition' => 'webkit,moz,o,ms',
		'user-drag' => 'webkit',
		'user-select' => 'webkit,moz,ms',
	);

	public function parse($css)
	{
		if (strpos($css, '/*') !== false) {
			throw new CSS3NotSupportedException('CSS with comments');
		}
		$css = preg_replace('/\s+/', ' ', $css);
		$result = array();
		foreach (explode('}', $css) as $chunk) {
			if (strpos($chunk, '{') === false) {
				$result[] = '';
				continue;
			}

			$parts = explode('{', $chunk);
			$block = array_pop($parts);
			$selector = array_pop($parts);

			try {
				$this->checkFatal($block);
				$block = $this->transformBlock($block);
			} catch (CSS3SanityException $e) {
				throw new CSS3SanityException($e->getMessage(). ' Selector "' . trim($selector).'"');
			}

			array_push($parts, $selector);
			array_push($parts, $block);
			$result[] = implode('{', $parts);
		}
		$css = implode('}', $result);

		$css = $this->transformAts($css);

		return $css;
	}

	public static function rgbatorgb($color)
	{
		$color = preg_replace('/rgba\((\d+,\d+,\d+),\d+\)/', 'rgb(\1)', $color);
		return $color;
	}

	protected function checkFatal($block)
	{
		if (preg_match('/border-radius\s*:[^;]*\//', $block))
			throw new CSS3NotSupportedException('border-radius slash notation');
		return true;
	}

	protected function re_at($rule, $header=null, $value=null)
	{
		$value = is_null($value) ? '(?:[^{}]+|{[^}]+})+' : str_replace('/', '\\/', preg_quote($value));
		$header = is_null($header) ? '[^{]+)?' : str_replace('/', '\\/', preg_quote($header)) .')';
		return '/@(?:-(?P<prefix>'.$this->prefixes.')-)?'.$rule
			.'\s*(?P<header>'.$header.'\s*{(?P<contents>'.$value.')}/';
	}

	protected function re_rule($rule, $value=null)
	{
		$value = is_null($value) ? '[^;}!]+' : str_replace('/', '\\/', preg_quote($value));
		return '/(?:-(?P<prefix>'.$this->prefixes.')-|[^a-zA-Z-])'.$rule
			.'\s*:\s*(?P<value>'.$value.')\s*(?P<important>![^;}]+)?;?/';
	}

	protected function special($rule, $value, $important)
	{
		switch ($rule) {
			case 'animation':
			case 'animation-duration':
				if (preg_match('/\b0\b/', $value)) {
					throw new CSS3SyntaxException('CSS3 animation duration should be "0s" instead of "0".');
				}
				return '';

			case 'opacity':
				return 'filter:alpha(opacity='.round(((float)trim($value))*100).')'.$important.';';

			default:
				return '';
		}
	}

	protected function transformAts($css)
	{
		foreach ($this->ats as $rule => $prefixes) {
			$count = preg_match_all($this->re_at($rule), $css, $matches, PREG_SET_ORDER);
			if (!$count) continue;

			foreach ($matches as $match) {
				// All values shold be the same, grab the first one
				$header = empty($match['header']) ? '' : trim($match['header']);
				$contents = trim($match['contents']);

				// Delete all previous rules
				$css = preg_replace($this->re_at($rule, $header, $contents), '', $css);

				// Official declaration is at the end
				// Make newest browsers take the newest rule
				$css = '@'.$rule.' '.$header.'{'.$contents.'}' . $css;

				// Add required prefixed rules
				foreach (explode(',', $prefixes) as $prefix)
					if ($prefix) // Avoid empty prefixes
						$css = '@-'.$prefix.'-'.$rule.' '.$header.'{'.$contents.'}' . $css;
			}
		}
		return $css;
	}

	protected function transformBlock($block)
	{
		foreach ($this->rules as $rule => $prefixes) {
			$count = preg_match($this->re_rule($rule), $block, $matches);
			if (!$count) continue;

			// All values shold be the same, grab the first one
			$value = trim($matches['value']);
			$important = empty($matches['important']) ? '' : trim($matches['important']);

			// Delete all previous rules
			$block = preg_replace($this->re_rule($rule, $value), '', $block);

			// Adding rules in reverse order on top of block

			// Official declaration is at the end
			// Make newest browsers take the newest rule
			$block = $rule.':'.$value.$important.';' . $block;

			// Add required prefixed rules
			foreach (explode(',', $prefixes) as $prefix)
				if ($prefix) // Avoid empty prefixes
					$block = '-'.$prefix.'-'.$rule.':'.$value.$important.';' . $block;

			// Special rules
			// Add on top to allow overwrites by the user (i.e. ms filter)
			$block = $this->special($rule, $value, $important) . $block;
		}

		$block = $this->transformBlockSpecial($block);
		return $block;
	}

	protected function transformBlockSpecial($block)
	{
		// -moz-user-select/drag requires -moz-none instead of none
		$block = preg_replace('/-moz-user-(select|drag):\s*none/',
			'-moz-user-\1:-moz-none', $block);

		return $block;
	}
}

class CSS3Exception extends Exception {}
class CSS3SanityException extends CSS3Exception {}
class CSS3SyntaxException extends CSS3Exception {}
class CSS3NotSupportedException extends CSS3Exception
{
	public function __construct($msg)
	{
		$msg = 'Unfortunately, CSS3 does not support ' . $msg . ' at this time.';
		parent::__construct($msg);
	}
}
