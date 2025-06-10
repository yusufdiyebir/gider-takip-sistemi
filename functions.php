<?php
session_start();

const DATA_FILE = __DIR__ . '/data.json';

function load_data() {
    if (!file_exists(DATA_FILE)) {
        $data = [
            'users' => [
                ['username' => 'admin', 'password' => password_hash('password', PASSWORD_DEFAULT)]
            ],
            'entries' => []
        ];
        file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT));
    }
    return json_decode(file_get_contents(DATA_FILE), true);
}

function save_data($data) {
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

function authenticate($username, $password) {
    $data = load_data();
    foreach ($data['users'] as $user) {
        if ($user['username'] === $username && password_verify($password, $user['password'])) {
            $_SESSION['user'] = $username;
            return true;
        }
    }
    return false;
}

function require_login() {
    if (!isset($_SESSION['user'])) {
        header('Location: index.php');
        exit();
    }
}

function add_entry($type, $amount, $date) {
    $data = load_data();
    $data['entries'][] = [
        'type' => $type,
        'amount' => (float)$amount,
        'date' => $date
    ];
    save_data($data);
}

function get_entries() {
    $data = load_data();
    return $data['entries'];
}

function calculate_profit_loss() {
    $entries = get_entries();
    $income = 0;
    $expense = 0;
    foreach ($entries as $e) {
        if ($e['type'] === 'income') {
            $income += $e['amount'];
        } else {
            $expense += $e['amount'];
        }
    }
    return $income - $expense;
}

function predict_next_month() {
    $entries = get_entries();
    if (count($entries) === 0) return 0;
    $sum = 0;
    foreach ($entries as $e) {
        $sum += $e['amount'] * ($e['type'] === 'income' ? 1 : -1);
    }
    // simple moving average as AI prediction
    return $sum / count($entries);
}
?>
