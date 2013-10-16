<?php
require_once '3s.php';

set_error_handler('errorConverter');
$d = new Display();
set_exception_handler(array($d, 'exception'));

$ts = new ThreeS();
$d->ts = $ts;
$ts->handle();

class Display
{
	public $ts;

	public function exception(Exception $e)
	{
		if ($e->getPrevious())
			return $this->exception($e->getPrevious());

		header('Content-Type: text/html', true, 500);

		if ($this->ts && !$this->ts->debug)
			$this->exceptionPublic($e);
		else
			$this->exceptionPrivate($e);
	}

	protected function exceptionPrivate($e)
	{
		$msg = $e->getMessage();
		echo $this->head();
		if ($e instanceof ThreeSSyntaxException) {
			$msg = preg_replace('/closing brackets?/', '\0 <code class=bracket>}</code>', $msg);
			echo '<p>'.$msg.' '.$this->inon($e).'.</p>';

			if ($e->getLine())
				echo $this->context($e->getContext(), $e->getLine());
		} elseif ($e instanceof ThreeS404Exception) {
			echo '<p>3s claims this URL returns 404.</p>';
			if ($msg)
				echo '<p>'.$msg.'.</p>';
		} else {
			echo '<p class=serious>'.get_class($e).'</p>';
			echo '<p>'.$msg.' '.$this->inon($e).'</p>';
			if (file_exists($e->getFile()) && is_readable($e->getFile()))
				echo $this->context(file_get_contents($e->getFile()), $e->getLine());
		}
	}

	protected function exceptionPublic(Exception $e)
	{
		if ($e instanceof ThreeS404Exception) {
			if (function_exists('http_response_code')) {
				http_response_code(404);
			} else {
				header('X-Ignore-This: 1', true, 404);
			}
			die('404');
		}

		echo $this->head();
		echo '<p>Something happened, but I shall not tell you what.</p>';
		echo '<p>To uncover the veil of mystery, add <code>$config[\'debug\'] = true</code> to your config.local.php.</p>';
	}

	protected function head()
	{
		return '<!doctype html><title>3s error</title><style>'
			.'html { margin: 0; padding: 0 }'
			.'body { margin: 0; padding: 20px; }'
			.'code { font-size: 110%; }'
			.'abbr { font-size: 90%; }'
			.'code { background-color: #FFE6C3; }'
			.'.bracket { font-size: 140%; }'
			.'.serious { font-size: 130%; color: #880300; }'
			.'ul, ol { list-style-position: inside; padding: 0; }'
			.'.context { font-family: monospace; white-space: pre;'
				.' border: 1px dashed black; border-width: 1px 0;'
				.' margin: 0 -20px }'
			.'li { padding: 0 20px; }'
			.' .offending { background-color: #FFDD8E }'
		.'</style>';
	}

	protected function context($file, $line, $size = 5)
	{
		$lines = explode("\n", $file);
		$line = $line - 1;

		$preStart = max(0, $line - $size);
		$pre = array_slice($lines, $preStart, $preStart + $line - $preStart);

		return '<ol class="context" start="'.($preStart+1).'">'
			. $this->printCodeLines($lines, $preStart, $line - $preStart)
			. $this->printCodeLines($lines, $line, 1, 'offending')
			. $this->printCodeLines($lines, $line + 1, $size)
			. '</ol>';
	}

	protected function inon(Exception $e)
	{
		$result = '';
		if ($e->getFile())
			$result .= 'in <strong>'.$this->prettyFilename($e->getFile()).'</strong>';
		if ($e->getLine())
			$result .= ' on line '.$e->getLine();
		if (method_exists($e, 'getLocation') && $e->getLocation())
			$result .= ' near <code>'.$e->getLocation().'</code>';
		return $result;
	}

	protected function prettyFilename($path)
	{
		return implode('/', array_slice(explode('/', $path), -3));
	}

	protected function printCodeLines($lines, $start, $size, $class = null)
	{
		$lines = array_slice($lines, $start, $size);
		$result = '';
		foreach ($lines as $line) {
			$result .= '<li'.(!is_null($class) ? ' class="'.$class.'"' : '').'>';
			$result .= htmlspecialchars($line) . "\n";
		}
		return $result;
	}
}

function errorConverter($errno, $errstr, $errfile, $errline)
{
	if (!($errno & error_reporting()))
		return;
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
