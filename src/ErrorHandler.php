<?php

namespace Errand;

use Infiltrate\FilterableInstanceTrait;

class ErrorHandler {

	use FilterableInstanceTrait;

	protected static $instance;

	protected static $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

	protected $handleErrors = false;

	protected $handleFatals = [];

	protected $reservedFatalMemory = '';

	protected $handleExceptions = false;

	protected $registeredFatalHandler = false;

	protected $previousErrorHandler;

	protected $previousErrorLevel;

	protected $previousExceptionHandler;

	protected $handlingException = false;

	public static function getInstance() {
		if (static::$instance === null) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	public static function register($options = [], ErrorHandler $instance = null) {
		$instance = $instance ?: static::getInstance();
		$options += [
			'level' => -1,
			'fatals' => [],
			'reserveFatalMemorySize' => 0,
			'callPreviousErrorHandler' => false,
			'callPreviousExceptionHandler' => false
		];
		$instance->registerErrorHandler($options['level'], $options['callPreviousErrorHandler']);
		$instance->registerExceptionHandler($options['callPreviousExceptionHandler']);
		$instance->registerFatalHandler($options['fatals'], $options['reserveFatalMemorySize']);
		return $instance;
	}

	public static function restore(ErrorHandler $instance = null) {
		$instance = $instance ?: static::getInstance();
		$instance->restoreErrorHandler();
		$instance->restoreFatalHandler();
		$instance->restoreExceptionHandler();
		return $instance;
	}

	public function registerErrorHandler($level = -1, $callPrevious = false) {
		$this->restoreErrorHandler();
		$this->previousErrorLevel = error_reporting($level);
		$previousErrorHandler = set_error_handler([$this, 'handleError'], $level);
		$this->handleErrors = true;
		if ($callPrevious) {
			$this->previousErrorHandler = $previousErrorHandler;
		}
	}

	public function registerFatalHandler($fatals = [], $reserveMemorySize = 0) {
		$this->handleFatals = $fatals ?: static::$fatals;
		$this->reservedFatalMemory = str_repeat(' ', 1024 * $reserveMemorySize);
		if ($this->registeredFatalHandler === false) {
			$this->registeredFatalHandler = true;
			register_shutdown_function([$this, 'handleFatalError']);
		}
	}

	public function registerExceptionHandler($callPrevious = false) {
		$this->restoreExceptionHandler();
		$previousExceptionHandler = set_exception_handler([$this, 'handleException']);
		$this->handleExceptions = true;
		if ($callPrevious) {
			$this->previousExceptionHandler = $previousExceptionHandler;
		}
	}

	public function restoreErrorHandler() {
		if ($this->handleErrors) {
			restore_error_handler();
			error_reporting($this->previousErrorLevel);
			unset($this->previousErrorHandler, $this->previousErrorLevel);
			$this->handleErrors = false;
		}
	}

	public function restoreFatalHandler() {
		$this->handleFatals = [];
		$this->reservedFatalMemory = '';
	}

	public function restoreExceptionHandler() {
		if ($this->handleExceptions) {
			unset($this->previousExceptionHandler);
			restore_exception_handler();
			$this->handleExceptions = false;
		}
	}

	public function handleError($code, $description, $file = null, $line = null, $context = null) {
		if (error_reporting() === 0) {
			return false;
		}
		$error = compact('code', 'description', 'file', 'line', 'context');
		$previous = $this->previousErrorHandler;
		$default = true;
		$params = compact('error', 'previous', 'default');
		return $this->filterMethod(__FUNCTION__, $params, function($self, $params){
			extract($params);
			if ($previous) {
				extract($error);
				return call_user_func($previous, $code, $message, $file, $line, $context);
			}
			if ($default) {
				return false;
			}
		});
	}

	public function handleFatalError() {
		if (empty($this->handleFatals)) {
			return;
		}
		$error = error_get_last();
		if (php_sapi_name() === 'cli' || !is_array($error)) {
			return;
		}
		if (!in_array($error['type'], $this->handleFatals, true)) {
			return;
		}
		$params = compact('error');
		$this->filterMethod(__FUNCTION__, $params, function($self, $params){
			extract($params);
			if (!isset($exception)) {
				if (!isset($error['code'])) {
					$error['code'] = 0;
				}
				$exception = new \ErrorException($error['message'], $error['code'], $error['type'], $error['file'], $error['line']);
			}
			$self->handleException($exception);
		});
	}

	public function handleException($exception) {
		if (ob_get_length()) {
			ob_end_clean();
		}
		if ($this->handlingException) {
			//encountered an exception, while handling an expception, BAIL OUT!
			throw $exception;
		}
		$exit = $exception->getCode();
		$previous = $this->previousExceptionHandler;
		$params = compact('exit', 'exception', 'previous');
		$this->handlingException = true;
		return $this->filterMethod(__FUNCTION__, $params, function($self, $params){
			extract($params);
			if ($previous) {
				call_user_func($previous, $exception);
			}
			if ($exit) {
				exit($exit);
			}
			if ($exception) {
				throw $exception;
			}
			$self->handlingException = false;
		});
	}
}

?>