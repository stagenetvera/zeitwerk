<?php
declare(strict_types=1);

function auth_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): void {
    if (!auth_user()) {
        header('Location: /login.php');
        exit;
    }
}

function login(PDO $pdo, string $email, string $password): bool {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        // fetch account
        $accStmt = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
        $accStmt->execute([$user['account_id']]);
        $account = $accStmt->fetch();
        $_SESSION['user'] = [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'account_id' => $user['account_id'],
            'is_admin' => (int)$user['is_admin'] === 1,
            'account_name' => $account ? $account['name'] : null,
        ];
        return true;
    }
    return false;
}

function register_user(PDO $pdo, string $name, string $email, string $password): bool {
    // simple check
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) return false;

    // new account per user (MVP)
    $pdo->beginTransaction();
    try {
        $accStmt = $pdo->prepare('INSERT INTO accounts(name) VALUES(?)');
        $accStmt->execute([$name . " Account"]);
        $account_id = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT INTO users(account_id,name,email,password_hash,is_admin) VALUES(?,?,?,?,1)');
        $ins->execute([$account_id, $name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        return false;
    }
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
