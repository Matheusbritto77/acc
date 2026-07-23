<?php
declare(strict_types=1);

function env_value(string $name, string $default = ''): string
{
	$value = getenv($name);
	return $value === false || $value === '' ? $default : $value;
}

function wait_for_database(): PDO
{
	$host = env_value('CANARY_DB_HOST', 'db');
	$port = env_value('CANARY_DB_PORT', '3306');
	$name = env_value('CANARY_DB_NAME', 'canary');
	$user = env_value('CANARY_DB_USER', 'canary');
	$password = env_value('CANARY_DB_PASSWORD', 'canary');
	$dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

	for ($attempt = 1; $attempt <= 90; ++$attempt) {
		try {
			return new PDO($dsn, $user, $password, [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			]);
		} catch (Throwable $error) {
			echo "Waiting for database ({$attempt}/90): {$error->getMessage()}\n";
			sleep(2);
		}
	}

	throw new RuntimeException('Database did not become available.');
}

function table_exists(PDO $pdo, string $table): bool
{
	$statement = $pdo->prepare(
		'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
	);
	$statement->execute([$table]);
	return (int) $statement->fetchColumn() > 0;
}

function wait_for_canary_schema(PDO $pdo): void
{
	for ($attempt = 1; $attempt <= 90; ++$attempt) {
		if (table_exists($pdo, 'accounts') && table_exists($pdo, 'players')) {
			return;
		}

		echo "Waiting for Canary schema ({$attempt}/90)\n";
		sleep(2);
	}

	throw new RuntimeException('Canary schema was not created before CanaryAAC setup.');
}

function execute_sql_script(PDO $pdo, string $sqlPath): void
{
	if (!file_exists($sqlPath)) {
		echo "SQL file not found: {$sqlPath}\n";
		return;
	}

	$sql = file_get_contents($sqlPath);
	// Basic multi-statement splitter
	$queries = explode(';', $sql);
	foreach ($queries as $query) {
		$query = trim($query);
		if ($query === '') {
			continue;
		}
		try {
			$pdo->exec($query);
		} catch (Throwable $e) {
			// Some ALTERS might fail if columns already exist, which is fine
			echo "Query info: " . $e->getMessage() . "\n";
		}
	}
}

$pdo = wait_for_database();
wait_for_canary_schema($pdo);

if (!table_exists($pdo, 'account_authentication')) {
	echo "Importing CanaryAAC database schema...\n";
	execute_sql_script($pdo, '/var/www/html/canaryaac.sql');
} else {
	echo "CanaryAAC schema already imported.\n";
}

// Generate the .env file
$siteUrl = env_value('URL', env_value('CANARYAAC_SITE_URL', env_value('MYAAC_SITE_URL', 'http://localhost:8080')));

$envContent = <<<ENV
URL='{$siteUrl}'
SERVER_PATH='/canary/'

# Database connection
DB_HOST='{$_ENV['CANARY_DB_HOST']}'
DB_NAME='{$_ENV['CANARY_DB_NAME']}'
DB_USER='{$_ENV['CANARY_DB_USER']}'
DB_PASS='{$_ENV['CANARY_DB_PASSWORD']}'
DB_PORT='{$_ENV['CANARY_DB_PORT']}'

# Config argon2
M_COST='1<<16'
T_COST='2'
PARALLELISM='2'

# Website configs
SITE_NAME='astarOT'
MAINTENANCE=false
DEV_MODE=false
MULTI_WORLD=false

OUTFITS_FOLDER='/resources/images/charactertrade/outfits'
ENV;

file_put_contents('/var/www/html/.env', $envContent);
echo ".env file generated successfully.\n";

// Patch includes/app.php for dynamic request host routing when URL is localhost/empty
$appFile = '/var/www/html/includes/app.php';
if (file_exists($appFile)) {
	$appCode = file_get_contents($appFile);
	$target = "define('URL', \$_ENV['URL']);";
	$replacement = <<<'PHP'
$detectedUrl = $_ENV['URL'] ?? '';
if (empty($detectedUrl) || $detectedUrl === 'http://localhost:8080' || $detectedUrl === 'http://127.0.0.1' || $detectedUrl === 'http://localhost') {
	$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https" : "http";
	if (!empty($_SERVER['HTTP_HOST'])) {
		$detectedUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
	}
}
define('URL', $detectedUrl ?: 'http://localhost:8080');
PHP;
	if (strpos($appCode, 'define(\'URL\', $_ENV[\'URL\']);') !== false) {
		$appCode = str_replace($target, $replacement, $appCode);
		file_put_contents($appFile, $appCode);
		echo "includes/app.php patched successfully for dynamic URL resolution.\n";
	}
}

// Ensure Twig cache directory exists and has correct permissions
$cacheDir = '/var/www/html/resources/view/cache';
if (!file_exists($cacheDir)) {
	mkdir($cacheDir, 0777, true);
	echo "Created Twig cache directory: {$cacheDir}\n";
}
chmod($cacheDir, 0777);


