<?php

declare(strict_types=1);
/*
 * PHP version 7.1
 *
 * @copyright Copyright (c) 2012-2017 EELLY Inc. (https://www.eelly.com)
 * @link      https://api.eelly.com
 * @license   衣联网版权所有
 */

namespace Eelly\Error;

use Eelly\Error\Handler\ServiceHandler;
use Eelly\Mvc\Application;
use Eelly\Mvc\ServiceApplication;
use Monolog\Handler\AbstractHandler;
use Monolog\Logger;
use Phalcon\Di\Injectable;
use Psr\Log\LogLevel;

/**
 * @author hehui<hehui@eelly.net>
 */
class Handler extends Injectable
{
    /**
     * fatal errors.
     *
     * @var array
     */
    private const FATAL_ERRORS = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
    ];

    /**
     * reserved memory.
     *
     * @var string
     */
    private $reservedMemory;

    /**
     * @var \Monolog\Logger
     */
    private $logger;

    /**
     * Registers itself as error and exception handler.
     *
     * @see https://github.com/php/php-src/blob/master/php.ini-production#L458
     */
    public function register(): self
    {
        // dev本地，local 待上线，prod 线上，test 测试
        ini_set('display_errors', '0');
        switch (ServiceApplication::$env) {
            case Application::ENV_PRODUCTION:
            case Application::ENV_STAGING:
            default:
                error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
                break;
            case Application::ENV_TEST:
            case Application::ENV_DEVELOPMENT:
                error_reporting(E_ALL);
                break;
        }
        $handler = new static();
        $handler->registerErrorHandler();
        $handler->registerExceptionHandler();
        $handler->registerFatalHandler();

        return $handler;
    }

    /**
     * @return \Monolog\Logger
     */
    public function getLogger()
    {
        if (null === $this->logger) {
            $di = $this->getDI();
            $this->logger = $di->getLogger();
            $serviceHandler = $di->getShared(ServiceHandler::class);
            $this->logger->pushHandler($serviceHandler);
        }

        return $this->logger;
    }

    public function registerErrorHandler(): void
    {
        set_error_handler([$this, 'handleError'], -1);
    }

    public function registerExceptionHandler(): void
    {
        set_exception_handler([$this, 'handleException']);
    }

    public function registerFatalHandler($reservedMemorySize = 20): void
    {
        register_shutdown_function([$this, 'handleFatalError'], getcwd());
        $this->reservedMemory = str_repeat(' ', 1024 * $reservedMemorySize);
    }

    public function handleError($code, $message, $file = '', $line = 0, $context = []): void
    {
        if (!($code & error_reporting())) {
            return;
        }
        if (!in_array($code, self::FATAL_ERRORS, true)) {
            $errorLevelMap = $this->defaultErrorLevelMap();
            $level = $errorLevelMap[$code] ?? LogLevel::CRITICAL;
            $this->getLogger()->log($level, self::codeToString($code).': '.$message, [
                'code' => $code,
                'message' => $message,
                'file' => $file,
                'line' => $line,
            ]);
        }
    }

    public function handleException(\Throwable $e): void
    {
        $errorLevelMap = $this->defaultErrorLevelMap();
        $level = $errorLevelMap[$e->getCode()] ?? LogLevel::ERROR;
        $this->getLogger()->log($level, 'Uncaught Exception: '.$e->getMessage(), [
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
            'class' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'traceAsString' => $e->getTraceAsString(),
        ]);

        exit(255);
    }

    public function handleFatalError($currPath): void
    {
        chdir($currPath);
        $this->reservedMemory = null;
        $lastError = error_get_last();
        if ($lastError && in_array($lastError['type'], self::FATAL_ERRORS, true)) {
            $logger = $this->getLogger();
            $logger->log(
                LogLevel::ALERT,
                'Fatal Error ('.self::codeToString($lastError['type']).'): '.$lastError['message'],
                ['code' => $lastError['type'], 'message' => $lastError['message'], 'file' => $lastError['file'], 'line' => $lastError['line']]
            );

            if ($logger instanceof Logger) {
                foreach ($logger->getHandlers() as $handler) {
                    if ($handler instanceof AbstractHandler) {
                        $handler->close();
                    }
                }
            }
        }
    }

    protected function defaultErrorLevelMap()
    {
        return [
            E_ERROR => LogLevel::CRITICAL,
            E_WARNING => LogLevel::WARNING,
            E_PARSE => LogLevel::ALERT,
            E_NOTICE => LogLevel::NOTICE,
            E_CORE_ERROR => LogLevel::CRITICAL,
            E_CORE_WARNING => LogLevel::WARNING,
            E_COMPILE_ERROR => LogLevel::ALERT,
            E_COMPILE_WARNING => LogLevel::WARNING,
            E_USER_ERROR => LogLevel::ERROR,
            E_USER_WARNING => LogLevel::WARNING,
            E_USER_NOTICE => LogLevel::NOTICE,
            E_STRICT => LogLevel::NOTICE,
            E_RECOVERABLE_ERROR => LogLevel::ERROR,
            E_DEPRECATED => LogLevel::NOTICE,
            E_USER_DEPRECATED => LogLevel::NOTICE,
        ];
    }

    private static function codeToString($code)
    {
        switch ($code) {
            case E_ERROR:
                return 'E_ERROR';
            case E_WARNING:
                return 'E_WARNING';
            case E_PARSE:
                return 'E_PARSE';
            case E_NOTICE:
                return 'E_NOTICE';
            case E_CORE_ERROR:
                return 'E_CORE_ERROR';
            case E_CORE_WARNING:
                return 'E_CORE_WARNING';
            case E_COMPILE_ERROR:
                return 'E_COMPILE_ERROR';
            case E_COMPILE_WARNING:
                return 'E_COMPILE_WARNING';
            case E_USER_ERROR:
                return 'E_USER_ERROR';
            case E_USER_WARNING:
                return 'E_USER_WARNING';
            case E_USER_NOTICE:
                return 'E_USER_NOTICE';
            case E_STRICT:
                return 'E_STRICT';
            case E_RECOVERABLE_ERROR:
                return 'E_RECOVERABLE_ERROR';
            case E_DEPRECATED:
                return 'E_DEPRECATED';
            case E_USER_DEPRECATED:
                return 'E_USER_DEPRECATED';
        }

        return 'Unknown PHP error';
    }
}