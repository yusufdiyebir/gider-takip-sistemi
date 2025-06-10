<?php
require_once 'functions.php';
require_login();

if (isset($_POST['type'], $_POST['amount'], $_POST['date'])) {
    add_entry($_POST['type'], $_POST['amount'], $_POST['date']);
    header('Location: admin.php');
    exit();
}

$entries = get_entries();
$profit_loss = calculate_profit_loss();
$prediction = predict_next_month();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin Panel</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <h1>Admin Panel</h1>
    <p><a href="logout.php">Logout</a></p>
    <h2>Add Entry</h2>
    <form method="post">
        <label>Type
            <select name="type">
                <option value="income">Income</option>
                <option value="expense">Expense</option>
            </select>
        </label><br>
        <label>Amount <input type="number" step="0.01" name="amount" required></label><br>
        <label>Date <input type="date" name="date" required></label><br>
        <button type="submit">Add</button>
    </form>
    <h2>Entries</h2>
    <table>
        <tr><th>Date</th><th>Type</th><th>Amount</th></tr>
        <?php foreach ($entries as $e): ?>
        <tr>
            <td><?php echo htmlspecialchars($e['date']); ?></td>
            <td><?php echo htmlspecialchars($e['type']); ?></td>
            <td><?php echo number_format($e['amount'], 2); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <h2>Profit / Loss: <?php echo number_format($profit_loss, 2); ?></h2>
    <h3>AI Prediction (next entry): <?php echo number_format($prediction, 2); ?></h3>
</body>
</html>
