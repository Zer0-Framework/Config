<?php

namespace Zer0\Config\Exceptions;

/**
 * Class YamlParseError
 * @package Zer0\Config\Exceptions
 */
class YamlParseError extends \Exception
{
    /**
     * @param \ErrorException $exception
     * @return \ErrorException|YamlParseError
     * @throws \ReflectionException
     */
    public static function deriveFromError(\ErrorException $exception): self
    {
        try {
            $traceProperty = (new \ReflectionClass('Exception'))->getProperty('trace');
            $traceProperty->setAccessible(true);

            $fileProperty = (new \ReflectionClass('Exception'))->getProperty('file');
            $fileProperty->setAccessible(true);

            $lineProperty = (new \ReflectionClass('Exception'))->getProperty('line');
            $lineProperty->setAccessible(true);

            preg_match('~^yaml_parse_file\(\): (.*?) \(line (\d+), column (\d+)\)$~', $exception->getMessage(), $match);
            list(, $message, $line, $column) = $match;
            $line = (int) $line;

            $trace = $traceProperty->getValue($exception);
            foreach ($trace as $el) {
                if ($el['function'] === 'yaml_parse_file') {
                    $yamlFile = $el['args'][0];
                    break;
                }
            }
            if (!isset($yamlFile)) {
                return $exception;
            };

            $self = new self($message, 0, $exception);
            array_shift($trace);
            $fileProperty->setValue($self, $yamlFile);
            $lineProperty->setValue($self, $line);
            $traceProperty->setValue($self, $trace);
            return $self;
        }
        finally {
            $fileProperty->setAccessible(false);
            $lineProperty->setAccessible(false);
            $traceProperty->setAccessible(false);
        }
    }
}
