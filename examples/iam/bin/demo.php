<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use IamExample\Domain\BindingId;
use IamExample\Domain\LocalId;
use IamExample\Domain\MembershipId;
use IamExample\Domain\Permission;
use IamExample\Domain\Scope;
use IamExample\Infrastructure\Runtime;
use IamExample\Infrastructure\Schema;
use Ultra\Time\SystemClock;

require dirname(__DIR__).'/bootstrap.php';

$dsn = getenv('IAM_DATABASE_DSN') ?: throw new RuntimeException('Set IAM_DATABASE_DSN to a Postgres connection URL.');
$connection = DriverManager::getConnection(new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql'])->parse($dsn));
Schema::install($connection);
$runtime = new Runtime($connection, new SystemClock());
// A fresh tenant makes the demonstration repeatable without deleting existing data.
$tenant = 'demo-'.bin2hex(random_bytes(8));
$administrator = new LocalId($tenant, 'admin');
$ada = new LocalId($tenant, 'ada');
$runtime->provisioning->createTenant($administrator);
$commands = $runtime->administration;
$commands->setSubject($administrator, 'ada');
$commands->setGroup($administrator, 'editors');
$commands->defineRole($administrator, 'editor', 'Document editor', [Permission::ReadDocument, Permission::EditDocument]);
$commands->setMembership($administrator, new MembershipId($tenant, 'editors', 'ada'), true);
$binding = BindingId::forPrincipal(new LocalId($tenant, 'editors'), 'group', 'editor', Scope::resource('document', 'handbook'));
$commands->grant($administrator, $binding);
$authorization = $runtime->authorization;
$print = static function (string $scenario, bool $allowed): void {
    echo $scenario.': '.($allowed ? 'ALLOW' : 'DENY').PHP_EOL;
};
echo 'Tenant: '.$tenant.PHP_EOL;
$print('Ada edits the handbook through her group', $authorization->allows($ada, Permission::EditDocument, Scope::resource('document', 'handbook')));
$print('Ada edits another document', $authorization->allows($ada, Permission::EditDocument, Scope::resource('document', 'private')));
$print('Ada administers IAM', $authorization->allows($ada, Permission::ManageIam, Scope::tenant()));
$commands->revoke($administrator, $binding);
$print('Ada edits the handbook after revocation', $authorization->allows($ada, Permission::EditDocument, Scope::resource('document', 'handbook')));
echo 'Audit entries: '.$connection->fetchOne('SELECT count(*) FROM iam_audit WHERE tenant_id = ?', [$tenant]).PHP_EOL;
