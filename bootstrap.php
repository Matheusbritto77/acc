<?php
declare(strict_types=1);

require '/var/www/html/app/Utils/RuntimeEnv.php';

function env_value(string $name, string $default = ''): string
{
	$value = getenv($name);
	return $value === false || $value === '' ? $default : $value;
}

function wait_for_database(): PDO
{
	$host = runtime_env_value('CANARY_DB_HOST', 'db');
	$port = runtime_env_value('CANARY_DB_PORT', '3306');
	$name = runtime_env_value('CANARY_DB_NAME', 'canary');
	$user = runtime_env_value('CANARY_DB_USER', 'canary');
	$password = runtime_env_value('CANARY_DB_PASSWORD', 'canary');
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

	throw new RuntimeException('Canary schema was not created before astarOT setup.');
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

function ensure_astarot_utf8mb4(PDO $pdo): void
{
	$statement = $pdo->query(
		"SELECT table_name
		 FROM information_schema.tables
		 WHERE table_schema = DATABASE()
		   AND table_name LIKE 'canary\\_%'
		   AND (table_collation IS NULL OR table_collation NOT LIKE 'utf8mb4%')"
	);

	if (!$statement) {
		return;
	}

	$tables = $statement->fetchAll(PDO::FETCH_COLUMN);
	foreach ($tables as $table) {
		try {
			$pdo->exec(
				sprintf(
					'ALTER TABLE `%s` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
					str_replace('`', '``', (string) $table)
				)
			);
			echo "Updated {$table} to utf8mb4.\n";
		} catch (Throwable $error) {
			echo "Charset migration info for {$table}: {$error->getMessage()}\n";
		}
	}
}

function normalize_world_location(string $location): int
{
	$location = trim($location);
	if ($location === '') {
		return 0;
	}

	if (ctype_digit($location)) {
		return (int) $location;
	}

	return match (strtoupper($location)) {
		'BRA', 'BRASIL', 'SOUTH AMERICA', 'SOUTH_AMERICA', 'SA' => 7,
		'USA', 'US', 'NORTH AMERICA', 'NORTH_AMERICA', 'NA' => 6,
		'EUR', 'EU', 'EUROPE' => 5,
		default => 0,
	};
}

function sync_world_endpoint(PDO $pdo): void
{
	$worldName = runtime_env_value('CANARY_SERVER_NAME', 'OTServBR-Global');
	$worldIp = runtime_env_value('CANARY_SERVER_IP', '127.0.0.1');
	$worldPort = (int) runtime_env_value('CANARY_GAME_PORT', '7172');
	$worldLocation = normalize_world_location(runtime_env_value('CANARY_SERVER_LOCATION', 'BRA'));

	$statement = $pdo->query('SELECT id FROM canary_worlds ORDER BY id ASC LIMIT 1');
	$worldId = $statement ? $statement->fetchColumn() : false;

	if ($worldId === false) {
		$insert = $pdo->prepare(
			'INSERT INTO canary_worlds (name, location, pvp_type, premium_type, transfer_type, battle_eye, world_type, ip, port)
			 VALUES (?, ?, 0, 0, 0, 0, 0, ?, ?)'
		);
		$insert->execute([$worldName, $worldLocation, $worldIp, $worldPort]);
		echo "Canary world endpoint created from runtime env.\n";
		return;
	}

	$update = $pdo->prepare('UPDATE canary_worlds SET name = ?, location = ?, ip = ?, port = ? WHERE id = ?');
	$update->execute([$worldName, $worldLocation, $worldIp, $worldPort, (int) $worldId]);
	echo "Canary world endpoint synchronized from runtime env.\n";
}

$pdo = wait_for_database();
wait_for_canary_schema($pdo);

if (!table_exists($pdo, 'account_authentication')) {
	echo "Importing astarOT database schema...\n";
	execute_sql_script($pdo, '/var/www/html/canaryaac.sql');
} else {
	echo "astarOT schema already imported.\n";
}

ensure_astarot_utf8mb4($pdo);

sync_world_endpoint($pdo);

// Generate the .env file
$siteUrl = runtime_env_value('URL', env_value('CANARYAAC_SITE_URL', env_value('MYAAC_SITE_URL', 'https://astarot.online')));
$redisUrl = runtime_env_value('REDIS_URL', env_value('REDIS_URL', ''));
$dbHost = runtime_env_value('CANARY_DB_HOST', 'db');
$dbPort = runtime_env_value('CANARY_DB_PORT', '3306');
$dbName = runtime_env_value('CANARY_DB_NAME', 'canary');
$dbUser = runtime_env_value('CANARY_DB_USER', 'canary');
$dbPassword = runtime_env_value('CANARY_DB_PASSWORD', 'canary');

$envContent = <<<ENV
URL='{$siteUrl}'
REDIS_URL='{$redisUrl}'
SERVER_PATH='/canary/'

# Database connection
DB_HOST='{$dbHost}'
DB_NAME='{$dbName}'
DB_USER='{$dbUser}'
DB_PASS='{$dbPassword}'
DB_PORT='{$dbPort}'

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

// Ensure Twig cache directory exists and has correct permissions
$cacheDir = '/var/www/html/resources/view/cache';
if (!file_exists($cacheDir)) {
	mkdir($cacheDir, 0777, true);
	echo "Created Twig cache directory: {$cacheDir}\n";
}
chmod($cacheDir, 0777);
