<?php
declare(strict_types=1);

// One-time application-database bootstrap. Called via PHP container stdin;
// the secret never appears in argv, GitHub CI or Docker metadata.
$email = 'admin@company-os.shop';
$organization = 'default';
$password = trim(stream_get_contents(STDIN));
if (preg_match('/\A[a-f0-9]{64}\z/D', $password) !== 1) {
    fwrite(STDERR, "ADMIN_BOOTSTRAP_INVALID_PASSWORD\n");
    exit(2);
}
$url = getenv('DATABASE_URL');
if (!is_string($url) || $url === '') {
    fwrite(STDERR, "ADMIN_BOOTSTRAP_NO_DATABASE_URL\n");
    exit(3);
}
$parts = parse_url($url);
if (!is_array($parts)
    || ($parts['scheme'] ?? '') !== 'mysql'
    || empty($parts['host'])
    || empty($parts['path'])
    || !isset($parts['user'], $parts['pass'])) {
    fwrite(STDERR, "ADMIN_BOOTSTRAP_UNEXPECTED_DSN\n");
    exit(4);
}
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $parts['host'],
    (int) ($parts['port'] ?? 3306),
    ltrim($parts['path'], '/')
);
try {
    $db = new PDO(
        $dsn,
        rawurldecode($parts['user']),
        rawurldecode($parts['pass']),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );

    // Bootstrap never resets an existing administrator's password.
    if ((int) $db->query("SELECT GET_LOCK('cos:first-owner-admin', 15)")->fetchColumn() !== 1) {
        throw new RuntimeException('Concurrent admin provisioning blocked');
    }
    try {
        $db->beginTransaction();
        $q = $db->query("SELECT COUNT(*) FROM tn_users WHERE role = 'admin'");
        if ((int) $q->fetchColumn() !== 0) {
            throw new RuntimeException('Admin already exists; bootstrap is not allowed');
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM tn_users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        if ((int) $stmt->fetchColumn() !== 0) {
            throw new RuntimeException('Bootstrap address already in use');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash)) {
            throw new RuntimeException('Password hash generation failed');
        }

        $stmt = $db->prepare(
            "INSERT INTO tn_users (organization_id, email, password_hash, full_name, role, status)
             VALUES (:org, :email, :hash, 'COS Administrator', 'admin', 'active')"
        );
        $stmt->execute(['org' => $organization, 'email' => $email, 'hash' => $hash]);
        $userId = (int) $db->lastInsertId();
        if ($userId < 1) {
            throw new RuntimeException('No admin ID returned');
        }

        $stmt = $db->prepare(
            "INSERT INTO cos_organization_memberships (organization_id, user_id, role, status)
             VALUES (:org, :uid, 'admin', 'ACTIVE')"
        );
        $stmt->execute(['org' => $organization, 'uid' => $userId]);

        $stmt = $db->prepare(
            "SELECT u.password_hash, m.role, m.status
             FROM tn_users u
             JOIN cos_organization_memberships m
               ON m.user_id = u.id AND m.organization_id = :org
             WHERE u.id = :uid AND u.status = 'active'"
        );
        $stmt->execute(['org' => $organization, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || ($row['role'] ?? '') !== 'admin'
            || ($row['status'] ?? '') !== 'ACTIVE'
            || !password_verify($password, (string) ($row['password_hash'] ?? ''))) {
            throw new RuntimeException('Administrator login validation failed');
        }
        $db->commit();
        printf("COS_OWNER_ADMIN_CREATED user_id=%d email=%s role=admin membership=ACTIVE\n", $userId, $email);
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $db->query("SELECT RELEASE_LOCK('cos:first-owner-admin')");
    }
} catch (Throwable $error) {
    // Do not risk logging DSN, passwords or SQL contents.
    fwrite(STDERR, "COS_OWNER_ADMIN_PROVISIONING_FAILED ".get_class($error)."\n");
    exit(1);
}
