<?php

namespace Errand;

use Infiltrate\FilterableInstanceTrait;

class ErrorHandler {

	use FilterableInstanceTrait;

	protected static $instance;

	protected static $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

	protected $handleErrors = false;

	protected $handleErrorLevel;

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
			'level' => E_ALL,
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

	public function registerErrorHandler($level = E_ALL, $callPrevious = false) {
		$this->restoreErrorHandler();
		$this->handleErrorLevel = $level;
		$previousErrorHandler = set_error_handler([$this, 'handleError'], $level);
		$this->handleErrors = true;
		if ($callPrevious) {
			$this->previousErrorLevel = is_int($callPrevious) ? $callPrevious : E_ALL;
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

	public function addErrorHandler($callable) {
		$this->addMethodFilter('handleError', $callable);
	}

	public function addFatalHandler($callable) {
		$this->addMethodFilter('handleFatalError', $callable);
	}

	public function addExceptionHandler($callable) {
		$this->addMethodFilter('handleException', $callable);
	}

	public function removeErrorHandler($callable) {
		$this->removeMethodFilter('handleError', $callable);
	}

	public function removeFatalHandler($callable) {
		$this->removeMethodFilter('handleFatalError', $callable);
	}

	public function removeExceptionHandler($callable) {
		$this->removeMethodFilter('handleException', $callable);
	}

	public function restoreErrorHandler() {
		if ($this->handleErrors) {
			restore_error_handler();
			unset($this->handleErrorLevel, $this->previousErrorHandler, $this->previousErrorLevel);
			$this->handleErrors = false;
		}
	}

	public function restoreFatalHandler() {
		$this->handleFatals = [];
		$this->reservedFatalMemory = '';
	}

	public function restoreExceptionHandler() {
		if ($this->handleExceptions) {
			restore_exception_handler();
			unset($this->previousExceptionHandler);
			$this->handleExceptions = false;
		}
	}

	public function handleError($code, $message, $file = null, $line = null, $context = null) {
		$previous = $this->previousErrorHandler;
		if (error_reporting() === 0 || !($this->handleErrorLevel & $code)) {
			if ($previous && ($this->previousErrorLevel & $error['code'])) {
				return call_user_func($previous, $code, $message, $file, $line, $context);
			}
			//errors suppressed or not hanlded
			return false;
		}
		$error = compact('code', 'message', 'file', 'line', 'context');
		$previous = $this->previousErrorHandler;
		$default = false;
		$params = compact('error', 'previous', 'default');
		return $this->filterMethod(__FUNCTION__, $params, function($self, $params){
			extract($params);
			if ($previous && ($self->previousErrorLevel & $error['code'])) {
				extract($error);
				call_user_func($previous, $code, $message, $file, $line, $context);
			}
			if ($default) {
				//bool:false = let the default error handler receive it
				return false;
			}
		});
	}

	public function handleFatalError() {
		if (empty($this->handleFatals)) {
			return;
		}
		$error = error_get_last();
		if (!is_array($error)) {
			return;
		}
		if (!in_array($error['type'], $this->handleFatals, true)) {
			return;
		}
		$error['code'] = 0;
		$params = compact('error');
		$this->filterMethod(__FUNCTION__, $params, function($self, $params){
			extract($params);
			if (!isset($exception)) {
				$exception = new \ErrorException($error['message'], $error['code'], $error['type'], $error['file'], $error['line']);
			}
			if ($exception) {
				$self->handleException($exception);
			}
		});
	}

	public function handleException($exception) {
		if ($this->handlingException) {
			//encountered an exception, while handling an expception, BAIL OUT!
			throw $exception;
		}
		$exit = $throw = false;
		$previous = $this->previousExceptionHandler;
		$params = compact('exit', 'throw', 'exception', 'previous');
		$this->handlingException = true;
		return $this->filterMethod(__FUNCTION__, $params, function($self, $params){
			extract($params);
			if ($previous) {
				call_user_func($previous, $exception);
			}
			if ($exit) {
				exit($exit);
			}
			if ($exception && $throw) {
				throw $exception;
			}
			$self->handlingException = false;
		});
	}
}

?>