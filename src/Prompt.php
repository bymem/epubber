<?php

// Reads from STDIN instead of using readline(), since the readline extension
// isn't guaranteed to be compiled into every PHP CLI build.
class Prompt
{
    public static function ask(string $message = ''): string
    {
        if ($message !== '') {
            echo $message;
        }

        $line = fgets(STDIN);

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    // Reads multiple lines until an empty line (or EOF) is entered
    public static function askMultiline(string $message = ''): string
    {
        if ($message !== '') {
            echo $message . "\n";
        }

        $result = '';

        while (true) {
            $line = fgets(STDIN);

            if ($line === false) {
                break;
            }

            $line = rtrim($line, "\r\n");

            if ($line === '') {
                break;
            }

            $result .= $line . "\n";
        }

        return $result;
    }
}
