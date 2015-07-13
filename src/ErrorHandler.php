<?php

namespace Errand;

use Infiltrate\Filters;
use Infiltrate\FilterableStaticTrait;

class ErrorHandler {

	use FilterableStaticTrait;

	protected static $_registered = false;

	public static function register($options = []) {
		$options += [
			'level' => -1,
			'class' => get_called_class(),
			'fatals' => [E_USER_ERROR, E_ERROR, E_PARSE]
		];
		extract($options);
		error_reporting($level);
		set_error_handler([$class, 'handleError'], $level);
		set_exception_handler([$class, 'handleException']);
		register_shutdown_function(function () use($class, $fatals) {
			if (!static::$_registered) {
				return;
			}
			if (php_sapi_name() === 'cli' || !($error = error_get_last())) {
				return;
			}
			if (!in_array($error['type'], $fatals, true)) {
				return;
			}
			$exception = new \ErrorException($error['message'], $error['type'], 0, $error['file'], $error['line']);
			$class::handleException($exception);
		});
		static::$_registered = true;
	}

	public static function restore() {
		restore_error_handler();
		restore_exception_handler();
		static::$_registered = false;
	}

	public static function handleError($code, $description, $file = null, $line = null, $context = null) {
		if (error_reporting() === 0) {
			return false;
		}
		$error = compact('code', 'description', 'file', 'line', 'context');
		$renderer = get_called_class();
		$params = compact('error', 'renderer');
		static::_filter(__FUNCTION__, $params, function($self, $params){
			extract($params);
			if (!empty($error)) {
				$renderer::renderError($error);
			}
		});
	}

	public static function handleException(\Exception $exception) {
		if (ob_get_length()) {
			ob_end_clean();
		}
		$exit = $exception->getCode();
		$renderer = get_called_class();
		$params = compact('exit', 'exception', 'renderer');
		static::_filter(__FUNCTION__, $params, function($self, $params){
			extract($params);
			if (!empty($exception)) {
				$renderer::renderException($exception);
			}
			if ($exit !== false) {
				exit($exit);
			}
		});
	}

	public static function renderError(array $error) {}

	public static function renderException(\Exception $exception) {}
}

?>