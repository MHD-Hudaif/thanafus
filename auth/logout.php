<?php
require_once __DIR__ . '/../config/auth.php';

// If the user is already logged out, just redirect them
if (empty($_SESSION['user_id'])) {
    header('Location: ' . app_url('/'));
    exit;
}

// If confirmed, proceed to log out
if (isset($_POST['confirm']) && $_POST['confirm'] === 'yes') {
    logout_user();
    header('Location: ' . app_url('/'));
    exit;
}

// Otherwise, show confirmation page
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Confirm Logout · Kauzariyya</title>
  <link href="https://fonts.googleapis.com/css2?family=Dosis:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
  <style>
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #0d100e, #1a221d);
      color: #f3f5f1;
      font-family: "Dosis", sans-serif;
    }
    .card {
      width: min(400px, calc(100% - 32px));
      padding: 36px 28px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 18px;
      background: rgba(13, 16, 14, 0.85);
      backdrop-filter: blur(20px);
      box-shadow: 0 24px 80px rgba(0,0,0,0.5);
      text-align: center;
    }
    .icon {
      font-size: 48px;
      color: #ff5555;
      margin-bottom: 20px;
    }
    h1 {
      font-size: 26px;
      margin: 0 0 12px;
      font-weight: 800;
    }
    p {
      color: #c1c8bd;
      font-size: 16px;
      line-height: 1.5;
      margin: 0 0 28px;
    }
    .buttons {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    button, a.btn {
      display: flex;
      align-items: center;
      justify-content: center;
      height: 48px;
      border-radius: 10px;
      font-size: 14px;
      font-weight: 700;
      text-decoration: none;
      transition: all 0.2s ease;
      cursor: pointer;
    }
    button.btn-danger {
      background: #ff4444;
      color: #fff;
      border: none;
      width: 100%;
    }
    button.btn-danger:hover {
      background: #ff2222;
      transform: translateY(-2px);
    }
    a.btn-secondary {
      background: rgba(255,255,255,0.08);
      color: #fff;
      border: 1px solid rgba(255,255,255,0.12);
    }
    a.btn-secondary:hover {
      background: rgba(255,255,255,0.15);
      transform: translateY(-2px);
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon"><i class="fa-solid fa-right-from-bracket"></i></div>
    <h1>Confirm Logout</h1>
    <p>Are you sure you want to log out of your account? This will log you out of all Kauzariyya platforms.</p>
    <form method="POST" class="buttons">
      <input type="hidden" name="confirm" value="yes" />
      <button type="submit" class="btn-danger">Yes, Log Out</button>
      <a href="javascript:history.back()" class="btn btn-secondary">No, Go Back</a>
    </form>
  </div>
</body>
</html>
