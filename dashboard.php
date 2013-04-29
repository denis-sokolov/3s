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

require 'config.php';
if (!$config['dashboard']) {
	header('Content-Type: text/plain', true, 403);
	die('3s dashboard is turned off.');
}

require_once '3s.php';
require_once 'inc/lessc.inc.php';
require_once 'inc/css3.php';

$ts = new ThreeS($config);
$less = new lessc();
$css3 = new CSS3();

// Fix for Apache 1.
$file = basename(__FILE__);
if (substr($_SERVER['REQUEST_URI'], -strlen($file)) == $file)
	$ts->notFound();

function exception_handler($e)
{
	echo '<div class="message error">';
	echo '<p>'.$e->getMessage().'</p>';
	echo '<ul>';
		foreach ($e->getTrace() as $r) {
			echo '<li>';
			if (isset($r['class']))
				echo $r['class'].'->';
			if (isset($r['function']));
				echo $r['function'];
			if (isset($r['file']))
				echo ' in '.basename($r['file']);
			if (isset($r['line']))
				echo ':'.$r['line'];

			echo '</li>';
		}
	echo '</ul>';
	echo '</div>';
}
set_exception_handler('exception_handler');

?>
<!DOCTYPE html>
<meta charset="utf-8">
<title>3s developer&rsquo;s dashboard</title>
<link rel=stylesheet href="<?php echo $ts->path('css', '3s-dashboard'); ?>">
<article>
	<?php $errors = 0; ?>
	<h1>3s</h1>
	<?php $t = $ts->tmp; if (!file_exists($t) || !is_writable($t)) {
		$errors++; ?>
		<p class="message warning">
			If this is a production environment,
			please create a temporary directory
			<code><?php
				echo dirname(__FILE__).'/'.$t;
			?></code>
			and make it writable.
		</p>
	<?php } ?>

	<?php
		$badFiles = array();
		foreach(glob($t.'/*.{css,js,png}', GLOB_BRACE) as $file)
		if (!is_writable($file) && !@chmod($file, 0777)) {
			$badFiles[] = $file;
			$errors++;
		}

		if ($badFiles) {
	?>
		<div class="message warning">
			Some files in temporary directory are not writable.
			This is not normal.
			<details>
				<summary>Files</summary>
				<?php echo implode(', ', $badFiles); ?>.
			</details>
		</div>
	<?php } ?>

	<?php
		foreach (all($ts) as $name => $h) {
			try {
				$h->data();
			} catch (ThreeSException $e) { ?>
				<div class="message error">
					Failed to compile <code><?php
						echo $name;
					?></code>.
					<p><?php echo $e->getMessage(); ?></p>
				</div>
				<?php
				$errors++;
			}
		}
	?>

	<?php if ($errors) { ?>
		<form action="" method=get>
			<input type=submit value="Check again">
		</form>
	<?php } else { ?>
		<p class="message success">
			Working smoothly.
		</p>
	<?php } ?>
</article>
<script src="<?php echo $ts->path('js', '3s-dashboard'); ?>"></script>

<?php
function all($ts, $types = 'css,js')
{
	$result = array();
	$dir = dirname(__FILE__);
	for ($i = 0; $i < 3; ++$i) {
		foreach (explode(',', $types) as $type)
			if (is_dir($dir.'/'.$type))
		foreach (scandir($dir.'/'.$type) as $name) {
			if (substr($name, 0, 1) == '.')
				continue;

			$key = implode('/', array_slice(explode('/',$dir), -3))
				.'/'.$type.'/'.$name;

			if (is_dir($dir.'/'.$type.'/'.$name)) {
				$result[$key] = $ts->handler((object) array(
					'keyword' => $name,
					'options' => '',
					'ext' => $type,
				));
			}

			// Remove the extension
			$name = substr($name, 0, -strlen($type)-1);

			if (file_exists($dir.'/'.$type.'/'.$name.'.'.$type)) {
				$result[$key] = $ts->handler((object) array(
					'keyword' => $name,
					'options' => '',
					'ext' => $type,
				));
			}
		}

		// Go up
		$dir = dirname($dir);
	}
	return $result;
}
