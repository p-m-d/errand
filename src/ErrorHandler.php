<?php

namespace Errand;

use Infiltrate\FilterableStaticTrait;

class ErrorHandler {

	use FilterableStaticTrait;

	protected static $handleErrors = false;

	protected static $handleFatals = [];

	protected static $reservedFatalMemory = '';

	protected static $handleExceptions = false;

	protected static $registeredFatalHandler = false;

	protected static $previousErrorHandler;

	protected static $previousErrorLevel;

	protected static $previousExceptionHandler;

	protected static $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

	public static function register($options = []) {
		$options += [
			'level' => -1,
			'fatals' => [],
			'reserveFatalMemorySize' => 0,
			'callPreviousErrorHandler' => false,
			'callPreviousExceptionHandler' => false
		];
		static::registerErrorHandler($options['level'], $options['callPreviousErrorHandler']);
		static::registerExceptionHandler($options['callPreviousExceptionHandler']);
		static::registerFatalHandler($options['fatals'], $options['reserveFatalMemorySize']);
	}

	public static function restore() {
		static::restoreErrorHandler();
		static::restoreFatalHandler();
		static::restoreExceptionHandler();
	}

	public static function registerErrorHandler($level = -1, $callPrevious = false) {
		static::restoreErrorHandler();
		$class = get_called_class();
		static::$previousErrorLevel = error_reporting($level);
		$previousErrorHandler = set_error_handler([$class, 'handleError'], $level);
		static::$handleErrors = true;
		if ($callPrevious) {
			static::$previousErrorHandler = $previousErrorHandler;
		}
	}

	public static function registerFatalHandler($fatals = [], $reserveMemorySize = 0) {
		static::$handleFatals = $fatals ?: static::$fatals;
		static::$reservedFatalMemory = str_repeat(' ', 1024 * $reserveMemorySize);
		if (static::$registeredFatalHandler === false) {
			static::$registeredFatalHandler = true;
			$class = get_called_class();
			register_shutdown_function([$class, 'handleFatalError']);
		}
	}

	public static function registerExceptionHandler($callPrevious = false) {
		static::restoreExceptionHandler();
		$class = get_called_class();
		$previousExceptionHandler = set_exception_handler([$class, 'handleException']);
		static::$handleExceptions = true;
		if ($callPrevious) {
			static::$previousExceptionHandler = $previousExceptionHandler;
		}
	}

	public static function restoreErrorHandler() {
		if (static::$handleErrors) {
			restore_error_handler();
			error_reporting(static::$previousErrorLevel);
			unset(static::$previousErrorHandler, static::$previousErrorLevel);
			static::$handleErrors = false;
		}
	}

	public static function restoreFatalHandler() {
		static::$handleFatals = [];
		static::$reservedFatalMemory = '';
	}

	public static function restoreExceptionHandler() {
		if (static::$handleExceptions) {
			unset(static::$previousExceptionHandler);
			restore_exception_handler();
			static::$handleExceptions = false;
		}
	}

	public static function handleError($code, $description, $file = null, $line = null, $context = null) {
		if (error_reporting() === 0) {
			return false;
		}
		$error = compact('code', 'description', 'file', 'line', 'context');
		$previous = static::$previousErrorHandler;
		$params = compact('error', 'previous');
		static::filterStaticMethod(__FUNCTION__, $params, function($self, $params){
			extract($params);
			if ($previous) {
				extract($error);
				return call_user_func($previous, $code, $message, $file, $line, $context);
			}
		});
	}

	public static function handleFatalError() {
		if (empty(static::$handleFatals)) {
			return;
		}
		$fatals = static::$handleFatals;
		$error = error_get_last();
		$params = compact('error', 'fatals');
		static::filterStaticMethod(__FUNCTION__, $params, function($self, $params){
			extract($params);
			if (php_sapi_name() === 'cli' || !is_array($error)) {
				return;
			}
			if (!in_array($error['type'], static::$handleFatals, true)) {
				return;
			}
			if (!isset($error['code'])) {
				$error['code'] = 0;
			}
			if (!isset($errorException)) {
				$errorException = new \ErrorException($error['message'], $error['code'], $error['type'], $error['file'], $error['line']);
			}
			$self::handleException($errorException);
		});
	}

	public static function handleException(\Exception $exception) {
		if (ob_get_length()) {
			ob_end_clean();
		}
		$exit = $exception->getCode();
		$previous = static::$previousExceptionHandler;
		$params = compact('exit', 'exception', 'previous');
		static::filterStaticMethod(__FUNCTION__, $params, function($self, $params){
			extract($params);
			if ($previous) {
				call_user_func($previous, $exception);
			}
			if ($exit !== false) {
				exit($exit ?: 1);
			}
		});
	}
}

?>