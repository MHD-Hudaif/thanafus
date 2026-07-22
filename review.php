<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/public-data.php';
require_once __DIR__ . '/config/auth.php';

$page = 'review';
$title = 'Feedback & Experience · Kauzariyya';

$sent = false;
$errorMessage = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
              str_contains($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest');

    $csrfToken = $_POST['csrf_token'] ?? null;
    $rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
    $comment = trim((string)($_POST['comment'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $userAgent = substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);

    if (!verify_csrf_token($csrfToken)) {
        $errorMessage = 'Security token validation failed. Please refresh and try again.';
    } elseif (!rate_limit_check("review:$ip", 5, 300)) {
        $errorMessage = 'Too many feedback requests. Please wait a few minutes before submitting again.';
    } elseif ($rating < 1 || $rating > 5) {
        $errorMessage = 'Please select a valid star rating (1 to 5).';
    } elseif ($comment === '') {
        $errorMessage = 'Please write a brief comment describing your experience.';
    } else {
        try {
            $pdo = $GLOBALS['musabaqa_pdo'];
            $activeEventId = tv_active_event_id();
            
            $stmt = $pdo->prepare("
                INSERT INTO musabaqa_reviews (event_id, rating, comment, name, ip_address, user_agent, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW())
            ");
            $stmt->execute([
                $activeEventId > 0 ? $activeEventId : null,
                $rating,
                $comment,
                $name !== '' ? $name : null,
                $ip,
                $userAgent
            ]);
            
            $sent = true;
            rate_limit_increment("review:$ip", 300);
            regenerate_csrf_token();
        } catch (Throwable $e) {
            error_log('Review submission failed: ' . $e->getMessage());
            $errorMessage = 'Unable to save your review at this time. Please try again.';
        }
    }

    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        if ($sent) {
            echo json_encode(['success' => true, 'message' => 'Thank you! Your feedback has been received.']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $errorMessage]);
        }
        exit;
    }
}

$csrfToken = generate_csrf_token();

require __DIR__ . '/includes/public-header.php';
?>

<section class="review-shell section-wrap">
  <div class="review-copy reveal">
    <p class="overline">Your voice matters</p>
    <h1>How was your<br /><em>experience?</em></h1>
    <p>Share what you enjoyed and what we can do better. Your opinion helps shape the next Kauzariyya Arts Festival.</p>
  </div>
  
  <div class="review-card reveal">
    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-error mb-4" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: #ef4444; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 600;">
        <?= e($errorMessage) ?>
      </div>
    <?php endif; ?>

    <?php if ($sent): ?>
      <div class="review-thanks">
        <span>✓</span>
        <h2>Thank you.</h2>
        <p>Your feedback has been received.</p>
        <a href="review.php" class="button button-ghost">Write another review</a>
      </div>
    <?php else: ?>
      <form method="POST" action="review.php" class="review-form" id="publicReviewForm">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        
        <div>
          <span class="field-label">Overall rating</span>
          <div class="rating-buttons" role="group" aria-label="Overall rating">
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <button type="button" data-value="<?= e($i) ?>" aria-label="Rate <?= $i ?> out of 5 stars">★</button>
            <?php endfor; ?>
          </div>
          <input type="hidden" name="rating" id="rating-input" value="0" required />
        </div>
        
        <label>
          <span class="field-label">What did you think?</span>
          <textarea name="comment" required rows="5" placeholder="Tell us about your festival experience…"></textarea>
        </label>
        
        <label>
          <span class="field-label">Your name <small>(optional)</small></span>
          <input type="text" name="name" placeholder="Name" />
        </label>
        
        <button class="button button-light" type="submit" id="submitReviewBtn">Send my review</button>
      </form>
    <?php endif; ?>
  </div>
</section>

<script>
(() => {
    document.querySelectorAll('.rating-buttons button').forEach(button => {
        button.addEventListener('click', () => {
            const rating = button.dataset.value;
            document.getElementById('rating-input').value = rating;
            document.querySelectorAll('.rating-buttons button').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.value <= rating);
            });
        });
    });
})();
</script>

<?php
require __DIR__ . '/includes/public-footer.php';
?>
