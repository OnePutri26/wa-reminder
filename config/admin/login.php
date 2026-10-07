<?php

session_start();

require_once __DIR__ . '/../database.php';

if (isset($_SESSION['admin_id'])) {

    header('Location: dashboard.php');

    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim(
        $_POST['username'] ?? ''
    );

    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {

        $error =
            'Username dan password wajib diisi.';

    } else {

        $stmt = $conn->prepare("
            SELECT
                id,
                username,
                password,
                full_name
            FROM admins
            WHERE username = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            's',
            $username
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        $admin =
            $result->fetch_assoc();

        $stmt->close();

        if (
            $admin &&
            password_verify(
                $password,
                $admin['password']
            )
        ) {

            session_regenerate_id(true);

            $_SESSION['admin_id'] =
                (int)$admin['id'];

            $_SESSION['admin_name'] =
                $admin['full_name'];

            $_SESSION['username'] =
                $admin['username'];

            header(
                'Location: dashboard.php'
            );

            exit;
        }

        $error =
            'Username atau password salah.';
    }
}

?>
<!DOCTYPE html>

<html lang="id">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<meta
    name="theme-color"
    content="#071a3a"
>

<title>
    Login | WA Reminder
</title>

<link
    rel="stylesheet"
    href="assets/login.css?v=3"
>

</head>

<body>

<main class="login-page">

    <div class="login-glow glow-one"></div>

    <div class="login-glow glow-two"></div>

    <section class="login-card">

        <div class="brand-mark">
            WA
        </div>

        <div class="eyebrow">
            CUSTOMER BILLING SYSTEM
        </div>

        <h1>
            WA Reminder
        </h1>

        <p class="subtitle">
            Kelola tagihan dan reminder pelanggan
            dari satu tempat.
        </p>

        <?php if ($error !== ''): ?>

            <div
                class="login-error"
                role="alert"
            >

                <span>!</span>

                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </div>

        <?php endif; ?>


        <form
            method="POST"
            class="login-form"
            autocomplete="on"
        >

            <label for="username">
                Username
            </label>

            <div class="input-wrap">

                <span class="input-icon">
                    ◎
                </span>

                <input
                    id="username"
                    name="username"
                    type="text"
                    placeholder="Masukkan username"
                    autocomplete="username"
                    required
                    autofocus
                >

            </div>


            <label for="password">
                Password
            </label>

            <div class="input-wrap">

                <span class="input-icon">
                    ●
                </span>

                <input
                    id="password"
                    name="password"
                    type="password"
                    placeholder="Masukkan password"
                    autocomplete="current-password"
                    required
                >

                <button
                    type="button"
                    class="toggle-password"
                    onclick="togglePassword()"
                >
                    ◉
                </button>

            </div>


            <button
                type="submit"
                class="login-submit"
            >

                Masuk ke dashboard

                <span>
                    →
                </span>

            </button>

        </form>


        <div class="login-footer">

            <span class="secure-dot"></span>

            Akses khusus administrator

        </div>

    </section>

</main>


<script>

function togglePassword()
{
    const input =
        document.getElementById('password');

    input.type =
        input.type === 'password'
            ? 'text'
            : 'password';
}

</script>

</body>

</html>