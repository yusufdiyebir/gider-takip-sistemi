<?php
require_once 'functions.php';

if (isset($_POST['username'], $_POST['password'])) {
    if (authenticate($_POST['username'], $_POST['password'])) {
        header('Location: admin.php');
        exit();
    } else {
        $error = 'Invalid credentials';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <h1>Login</h1>
    <?php if (!empty($error)) echo '<p class="error">'.$error.'</p>'; ?>
    <form method="post">
        <label>Username <input type="text" name="username" required></label><br>
        <label>Password <input type="password" name="password" required></label><br>
        <button type="submit">Login</button>
    </form>
</body>
</html>
