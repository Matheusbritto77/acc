<?php
declare(strict_types=1);

if (!function_exists('runtime_env_file')) {
	function runtime_env_file(): string
	{
		return getenv('CANARY_RUNTIME_ENV_FILE') ?: '/tmp/canaryaac-runtime.env';
	}
}

if (!function_exists('runtime_env_value')) {
	function runtime_env_value(string $name, string $default = ''): string
	{
		$file = runtime_env_file();
		if (is_file($file)) {
			$lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			if ($lines !== false) {
				foreach ($lines as $line) {
					$line = trim($line);
					if ($line === '' || $line[0] === '#') {
						continue;
					}

					$separator = strpos($line, '=');
					if ($separator === false) {
						continue;
					}

					$key = trim(substr($line, 0, $separator));
					if ($key !== $name) {
						continue;
					}

					return trim(substr($line, $separator + 1));
				}
			}
		}

		$value = getenv($name);
		return $value === false || $value === '' ? $default : $value;
	}
}
