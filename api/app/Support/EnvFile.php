<?php

namespace App\Support;

// Minimal .env editor for the installer and in-app settings that persist
// environment values (instances never run config:cache, so .env is live).
class EnvFile
{
    public static function set(array $values): void
    {
        $path = base_path('.env');
        $env = file_exists($path) ? file_get_contents($path) : '';
        foreach ($values as $key => $value) {
            // Never let a value carry a newline into .env — it would inject an
            // arbitrary extra config line (e.g. a Google secret flipping APP_DEBUG).
            if ($value !== null) {
                $value = str_replace(["\r", "\n"], '', $value);
                // Quote anything dotenv would misparse bare (spaces, #, quotes —
                // SMTP passwords especially); escape \ and " inside the quotes.
                if (preg_match('~[^A-Za-z0-9_.@:+/=-]~', $value)) {
                    $value = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
                }
            }
            $line = $value === null ? '' : $key.'='.$value;
            if (preg_match("/^#?\s*{$key}=.*/m", $env)) {
                // strtr: the line is a preg_replace *replacement*, where bare $ and \
                // are capture references (a "p4$$word" would corrupt the file).
                $env = preg_replace("/^#?\s*{$key}=.*\n?/m", $line === '' ? '' : strtr($line, ['\\' => '\\\\', '$' => '\\$'])."\n", $env);
            } elseif ($line !== '') {
                $env = rtrim($env)."\n".$line."\n";
            }
        }
        file_put_contents($path, $env);
    }
}
