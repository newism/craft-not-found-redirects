<?php

namespace newism\notfoundredirects\console\controllers;

use yii\console\Exception;

trait ReadInputTrait
{
    public function readInput(string $path): string
    {
        if ($path === '-') {
            if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
                throw new Exception('Provide a filename or pipe data via stdin');
            }
            return stream_get_contents(STDIN);
        }

        if (!file_exists($path)) {
            throw new Exception("File not found: $path");
        }

        return file_get_contents($path);
    }
}